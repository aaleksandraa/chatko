<?php

namespace App\Services\AI;

class ResponseValidationService
{
    /**
     * @param array<string, mixed> $response
     * @param array<int, int> $allowedProductIds
     * @return array<string, mixed>
     */
    public function validate(array $response, array $allowedProductIds): array
    {
        $answerText = (string) ($response['answer_text'] ?? 'Nisam siguran da imam dovoljno podataka za preciznu preporuku.');
        $response['answer_text'] = $this->limitAnswerLength($answerText);

        $recommended = $response['recommended_product_ids'] ?? [];
        if (! is_array($recommended)) {
            $recommended = [];
        }

        $allowedMap = array_fill_keys($allowedProductIds, true);

        $response['recommended_product_ids'] = array_values(array_filter(array_map('intval', $recommended), static fn (int $id): bool => isset($allowedMap[$id])));

        $response['cta_type'] = isset($response['cta_type']) ? (string) $response['cta_type'] : null;
        $response['cta_label'] = isset($response['cta_label']) ? (string) $response['cta_label'] : null;
        $response['needs_handoff'] = (bool) ($response['needs_handoff'] ?? false);
        $response['lead_capture_suggested'] = (bool) ($response['lead_capture_suggested'] ?? false);
        $response['detected_intent'] = (string) ($response['detected_intent'] ?? 'product_recommendation');
        $response['confidence'] = isset($response['confidence']) ? (float) $response['confidence'] : 0.5;

        return $response;
    }

    private function limitAnswerLength(string $text): string
    {
        $maxChars = max(120, (int) config('services.openai.response_max_chars', 520));
        $normalized = trim($text);

        if (mb_strlen($normalized) <= $maxChars) {
            return $normalized;
        }

        $trimmed = rtrim(mb_substr($normalized, 0, $maxChars));
        $lastSentence = max(
            (int) mb_strrpos($trimmed, '.'),
            (int) mb_strrpos($trimmed, '!'),
            (int) mb_strrpos($trimmed, '?'),
        );

        if ($lastSentence >= 80) {
            return trim(mb_substr($trimmed, 0, $lastSentence + 1));
        }

        return $trimmed.'...';
    }
}
