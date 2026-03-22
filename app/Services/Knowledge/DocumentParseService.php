<?php

namespace App\Services\Knowledge;

use RuntimeException;

class DocumentParseService
{
    public function parse(string $filePath, ?string $mimeType = null): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'txt') {
            $contents = file_get_contents($filePath);

            if ($contents === false) {
                throw new RuntimeException('Failed to read TXT document.');
            }

            return $contents;
        }

        if ($extension === 'pdf' || $extension === 'docx') {
            throw new RuntimeException('PDF/DOCX parsing hook exists, parser not configured yet. Use text fallback or integrate dedicated parser package.');
        }

        throw new RuntimeException('Unsupported document type: '.$extension);
    }
}
