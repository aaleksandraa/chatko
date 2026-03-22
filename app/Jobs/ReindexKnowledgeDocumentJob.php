<?php

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeUploadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReindexKnowledgeDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $documentId)
    {
    }

    public function handle(KnowledgeUploadService $uploadService): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document === null) {
            return;
        }

        $uploadService->reindex($document);
    }
}
