<?php

namespace App\Support;

class IntegrationSyncFrequency
{
    public const MANUAL = 'manual';

    public const EVERY_5M = 'every_5m';

    public const EVERY_15M = 'every_15m';

    public const EVERY_30M = 'every_30m';

    public const HOURLY = 'hourly';

    public const EVERY_2H = 'every_2h';

    public const EVERY_6H = 'every_6h';

    public const DAILY = 'daily';

    /**
     * @return array<int, string>
     */
    public static function allowedValues(): array
    {
        return [
            self::MANUAL,
            self::EVERY_5M,
            self::EVERY_15M,
            self::EVERY_30M,
            self::HOURLY,
            self::EVERY_2H,
            self::EVERY_6H,
            self::DAILY,
        ];
    }

    public static function normalize(?string $value): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '' || in_array($raw, ['none', 'off', 'disabled'], true)) {
            return self::MANUAL;
        }

        return match ($raw) {
            '5m', '5min', '5mins', 'every5m', 'every_5m', 'every-5m' => self::EVERY_5M,
            '15m', '15min', '15mins', 'every15m', 'every_15m', 'every-15m' => self::EVERY_15M,
            '30m', '30min', '30mins', 'every30m', 'every_30m', 'every-30m' => self::EVERY_30M,
            '1h', '60m', 'hourly', 'every_hour', 'every-hour' => self::HOURLY,
            '2h', 'every2h', 'every_2h', 'every-2h' => self::EVERY_2H,
            '6h', 'every6h', 'every_6h', 'every-6h' => self::EVERY_6H,
            '24h', '1d', 'daily', 'every_day', 'every-day' => self::DAILY,
            default => in_array($raw, self::allowedValues(), true) ? $raw : self::MANUAL,
        };
    }

    public static function intervalSeconds(?string $value): ?int
    {
        $normalized = self::normalize($value);

        return match ($normalized) {
            self::EVERY_5M => 5 * 60,
            self::EVERY_15M => 15 * 60,
            self::EVERY_30M => 30 * 60,
            self::HOURLY => 60 * 60,
            self::EVERY_2H => 2 * 60 * 60,
            self::EVERY_6H => 6 * 60 * 60,
            self::DAILY => 24 * 60 * 60,
            default => null,
        };
    }
}
