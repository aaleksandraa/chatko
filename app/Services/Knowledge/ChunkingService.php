<?php

namespace App\Services\Knowledge;

class ChunkingService
{
    /**
     * @return array<int, string>
     */
    public function chunk(string $text, int $maxLength = 900, int $overlap = 120): array
    {
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\n+/', $text) ?: [])));

        if ($paragraphs === []) {
            return [];
        }

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $candidate = $current === '' ? $paragraph : $current."\n\n".$paragraph;

            if (mb_strlen($candidate) <= $maxLength) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            if (mb_strlen($paragraph) > $maxLength) {
                $paragraphChunks = $this->splitLongParagraph($paragraph, $maxLength, $overlap);
                foreach ($paragraphChunks as $paragraphChunk) {
                    $chunks[] = $paragraphChunk;
                }
                $current = '';
                continue;
            }

            $current = $paragraph;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    private function splitLongParagraph(string $paragraph, int $maxLength, int $overlap): array
    {
        $words = preg_split('/\s+/', $paragraph) ?: [];
        $chunks = [];
        $current = [];

        foreach ($words as $word) {
            $candidateWords = $current;
            $candidateWords[] = $word;
            $candidate = implode(' ', $candidateWords);

            if (mb_strlen($candidate) <= $maxLength) {
                $current = $candidateWords;
                continue;
            }

            $chunk = implode(' ', $current);
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            $tail = mb_substr($chunk, max(0, mb_strlen($chunk) - $overlap));
            $current = array_values(array_filter(preg_split('/\s+/', $tail.' '.$word) ?: []));
        }

        if ($current !== []) {
            $chunks[] = implode(' ', $current);
        }

        return $chunks;
    }
}
