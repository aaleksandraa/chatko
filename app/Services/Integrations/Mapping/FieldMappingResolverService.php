<?php

namespace App\Services\Integrations\Mapping;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class FieldMappingResolverService
{
    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    public function resolve(array $source, array $mapping): array
    {
        $resolved = [];

        foreach ($mapping as $targetField => $definition) {
            if (is_string($definition)) {
                $resolved[$targetField] = data_get($source, $definition);
                continue;
            }

            if (! is_array($definition)) {
                continue;
            }

            $value = $this->resolveValue($source, $definition);
            $value = $this->applyTransforms($value, $this->normalizeTransforms($definition));
            $resolved[$targetField] = $value;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $definition
     */
    private function resolveValue(array $source, array $definition): mixed
    {
        $hasDefault = array_key_exists('default', $definition);
        $default = $hasDefault ? $definition['default'] : null;
        $sentinel = new \stdClass();

        $from = $definition['from'] ?? null;
        if (is_array($from) && $from !== []) {
            foreach ($from as $path) {
                if (! is_string($path) || trim($path) === '') {
                    continue;
                }

                $candidate = data_get($source, $path, $sentinel);
                if ($candidate !== $sentinel && $candidate !== null && $candidate !== '') {
                    return $candidate;
                }
            }

            return $default;
        }

        $path = $definition['path'] ?? null;
        if (! is_string($path) || trim($path) === '') {
            return $default;
        }

        $value = data_get($source, $path, $sentinel);

        if ($value === $sentinel) {
            return $default;
        }

        if (($value === null || $value === '') && $hasDefault) {
            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array{name: string, options: array<string, mixed>}>
     */
    private function normalizeTransforms(array $definition): array
    {
        $result = [];

        if (array_key_exists('transform', $definition)) {
            $transform = $definition['transform'];

            if (is_string($transform) && $transform !== '') {
                $result[] = [
                    'name' => $transform,
                    'options' => is_array($definition['transform_options'] ?? null) ? $definition['transform_options'] : [],
                ];
            } elseif (is_array($transform)) {
                if (isset($transform['name']) && is_string($transform['name'])) {
                    $result[] = [
                        'name' => (string) $transform['name'],
                        'options' => $this->extractOptions($transform),
                    ];
                } else {
                    foreach ($transform as $step) {
                        if (is_string($step) && $step !== '') {
                            $result[] = ['name' => $step, 'options' => []];
                            continue;
                        }

                        if (is_array($step) && isset($step['name']) && is_string($step['name'])) {
                            $result[] = [
                                'name' => (string) $step['name'],
                                'options' => $this->extractOptions($step),
                            ];
                        }
                    }
                }
            }
        }

        $transforms = $definition['transforms'] ?? null;
        if (is_array($transforms)) {
            foreach ($transforms as $step) {
                if (is_string($step) && $step !== '') {
                    $result[] = ['name' => $step, 'options' => []];
                    continue;
                }

                if (is_array($step) && isset($step['name']) && is_string($step['name'])) {
                    $result[] = [
                        'name' => (string) $step['name'],
                        'options' => $this->extractOptions($step),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function extractOptions(array $step): array
    {
        $options = $step;
        unset($options['name']);

        return $options;
    }

    /**
     * @param array<int, array{name: string, options: array<string, mixed>}> $transforms
     */
    private function applyTransforms(mixed $value, array $transforms): mixed
    {
        foreach ($transforms as $transform) {
            $value = $this->applyTransform($value, $transform['name'], $transform['options']);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyTransform(mixed $value, string $transform, array $options): mixed
    {
        return match (strtolower(trim($transform))) {
            'strip_html' => is_string($value) ? trim(strip_tags($value)) : $value,
            'trim' => is_string($value) ? trim($value) : $value,
            'lowercase' => is_string($value) ? Str::lower($value) : $value,
            'uppercase' => is_string($value) ? Str::upper($value) : $value,
            'slug' => is_string($value) ? Str::slug($value) : $value,
            'decimal' => is_scalar($value) ? (float) str_replace(',', '.', (string) $value) : $value,
            'integer' => is_scalar($value) ? (int) $value : $value,
            'boolean' => $this->toBoolean($value, $options),
            'bool_from_stock_status' => is_string($value)
                ? in_array(strtolower($value), ['instock', 'in_stock', 'available', 'true', '1', 'yes'], true)
                : (bool) $value,
            'array_wrap' => is_array($value) ? $value : ($value === null ? [] : [$value]),
            'first' => is_array($value) ? (Arr::first($value) ?? null) : $value,
            'json_decode' => $this->jsonDecode($value),
            'split', 'csv_to_array' => $this->splitToArray($value, (string) ($options['delimiter'] ?? ',')),
            'pipe_to_array' => $this->splitToArray($value, '|'),
            'join' => $this->joinArray($value, (string) ($options['delimiter'] ?? ', ')),
            'extract_image_srcs' => $this->extractImageSrcs($value),
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function toBoolean(mixed $value, array $options): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value > 0;
        }

        if (! is_string($value)) {
            return (bool) $value;
        }

        $truthy = array_map('strtolower', array_map('strval', $options['truthy'] ?? ['true', '1', 'yes', 'y', 'instock', 'available']));
        $falsy = array_map('strtolower', array_map('strval', $options['falsy'] ?? ['false', '0', 'no', 'n', 'outofstock', 'unavailable']));
        $normalized = strtolower(trim($value));

        if (in_array($normalized, $truthy, true)) {
            return true;
        }

        if (in_array($normalized, $falsy, true)) {
            return false;
        }

        return (bool) $normalized;
    }

    private function jsonDecode(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function splitToArray(mixed $value, string $delimiter): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $parts = array_values(array_filter(array_map('trim', explode($delimiter, $value)), static fn ($part) => $part !== ''));

        return $parts;
    }

    private function joinArray(mixed $value, string $delimiter): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $parts = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $parts[] = (string) $item;
            }
        }

        return implode($delimiter, $parts);
    }

    private function extractImageSrcs(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $images = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $images[] = ['src' => $item];
                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $src = $item['src'] ?? $item['url'] ?? $item['source_url'] ?? null;
            if (! is_string($src) || $src === '') {
                continue;
            }

            $images[] = [
                'src' => $src,
                'alt' => $item['alt'] ?? $item['alt_text'] ?? $item['altText'] ?? null,
            ];
        }

        return $images;
    }
}

