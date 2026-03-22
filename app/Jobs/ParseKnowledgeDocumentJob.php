<?php

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\DocumentParseService;
use App\Services\Knowledge\KnowledgeUploadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ParseKnowledgeDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $documentId)
    {
    }

    public function handle(KnowledgeUploadService $uploadService, DocumentParseService $parseService): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document === null) {
            return;
        }

        $uploadService->parseAndIndexFromStoredFile($document, $parseService);
    }
}
