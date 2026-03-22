<?php

namespace App\Services\Conversation;

class EntityExtractionService
{
    /**
     * @return array<string, mixed>
     */
    public function extract(string $message): array
    {
        $entities = [];
        $text = mb_strtolower($message);

        if (preg_match('/\bdo\s+(\d+[\.,]?\d*)\s*(km|bam|eur|e)?\b/', $text, $matches) === 1) {
            $entities['budget_max'] = (float) str_replace(',', '.', $matches[1]);
        }

        if (preg_match('/\b(za\s+)?(suhu\s+kozu|osjetljivu\s+kozu|suhu\s+kosu|bradu|brada|laptop|telefon|serum|krema|slusalice)\b/u', $text, $matches) === 1) {
            $entities['category'] = trim((string) ($matches[2] ?? $matches[1] ?? ''));
        } else {
            $dynamicCategory = $this->extractCategoryFromPhrase($text);
            if ($dynamicCategory !== null) {
                $entities['category'] = $dynamicCategory;
            }
        }

        if (preg_match('/\b(muskarac|zena|dijete|djeca)\b/', $text, $matches) === 1) {
            $entities['target_user'] = $matches[1];
        }

        return $entities;
    }

    private function extractCategoryFromPhrase(string $text): ?string
    {
        if (preg_match('/\bza\s+([^\.,!\?\n\r]{2,70})/u', $text, $matches) !== 1) {
            return null;
        }

        $phrase = trim((string) ($matches[1] ?? ''));
        if ($phrase === '') {
            return null;
        }

        $phrase = (string) preg_replace('/\b(do|sa|bez|koji|koja|koje|sto|ali|jer)\b.*/u', '', $phrase);
        $tokens = preg_split('/\s+/', $phrase) ?: [];

        $stopWords = [
            'za', 'mi', 'je', 'u', 'na', 'i', 'ili', 'od', 'do', 'sa',
            'treba', 'nesto', 'neko', 'neka', 'neki', 'neku', 'nekog',
            'ovo', 'ono', 'ovaj', 'taj',
        ];

        $keywords = [];
        foreach ($tokens as $token) {
            $clean = trim($token, " \t\n\r\0\x0B,.;:!?()[]{}\"'");
            if ($clean === '' || mb_strlen($clean) < 3 || in_array($clean, $stopWords, true)) {
                continue;
            }

            $keywords[] = $clean;
            if (count($keywords) >= 3) {
                break;
            }
        }

        if ($keywords === []) {
            return null;
        }

        return implode(' ', $keywords);
    }
}
