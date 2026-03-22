<?php

namespace App\Services\Knowledge;

class TextCleaningService
{
    public function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/[\t ]+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
