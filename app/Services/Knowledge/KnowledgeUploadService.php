<?php

namespace App\Services\Knowledge;

use App\Jobs\ParseKnowledgeDocumentJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class KnowledgeUploadService
{
    public function __construct(
        private readonly TextCleaningService $textCleaningService,
        private readonly ChunkingService $chunkingService,
        private readonly KnowledgeEmbeddingService $embeddingService,
    ) {
    }

    public function uploadFile(Tenant $tenant, UploadedFile $file, array $meta): KnowledgeDocument
    {
        $path = $file->store("knowledge/{$tenant->id}", 'local');

        $document = KnowledgeDocument::query()->create([
            'tenant_id' => $tenant->id,
            'source_type' => 'file_upload',
            'title' => $meta['title'] ?? $file->getClientOriginalName(),
            'type' => $meta['type'] ?? 'company_info',
            'language' => $meta['language'] ?? 'bs',
            'visibility' => $meta['visibility'] ?? 'public_for_ai',
            'ai_allowed' => (bool) ($meta['ai_allowed'] ?? true),
            'internal_only' => (bool) ($meta['internal_only'] ?? false),
            'status' => 'uploaded',
            'original_file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'tags_json' => $meta['tags_json'] ?? null,
            'uploaded_by' => $meta['uploaded_by'] ?? null,
        ]);

        ParseKnowledgeDocumentJob::dispatch($document->id);

        return $document;
    }

    public function createTextDocument(Tenant $tenant, array $payload): KnowledgeDocument
    {
        $contentRaw = (string) ($payload['content_raw'] ?? '');
        $contentClean = $this->textCleaningService->clean($contentRaw);

        $document = KnowledgeDocument::query()->create([
            'tenant_id' => $tenant->id,
            'source_type' => 'manual_text',
            'title' => $payload['title'],
            'type' => $payload['type'] ?? 'faq',
            'language' => $payload['language'] ?? 'bs',
            'visibility' => $payload['visibility'] ?? 'public_for_ai',
            'ai_allowed' => (bool) ($payload['ai_allowed'] ?? true),
            'internal_only' => (bool) ($payload['internal_only'] ?? false),
            'status' => 'parsed',
            'source_ref' => $payload['source_ref'] ?? null,
            'tags_json' => $payload['tags_json'] ?? null,
            'content_raw' => $contentRaw,
            'content_clean' => $contentClean,
            'uploaded_by' => $payload['uploaded_by'] ?? null,
        ]);

        $this->indexDocument($document);

        return $document->fresh();
    }

    public function parseAndIndexFromStoredFile(KnowledgeDocument $document, DocumentParseService $parseService): KnowledgeDocument
    {
        $document->update(['status' => 'parsing', 'error_message' => null]);

        try {
            if ($document->original_file_path === null) {
                throw new \RuntimeException('Document file path missing.');
            }

            $absolutePath = Storage::disk('local')->path($document->original_file_path);
            $raw = $parseService->parse($absolutePath, $document->mime_type);
            $clean = $this->textCleaningService->clean($raw);

            $document->update([
                'content_raw' => $raw,
                'content_clean' => $clean,
                'status' => 'parsed',
            ]);

            $this->indexDocument($document);

            return $document->fresh();
        } catch (\Throwable $e) {
            $document->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return $document->fresh();
        }
    }

    public function reindex(KnowledgeDocument $document): KnowledgeDocument
    {
        if (($document->content_clean ?? '') === '') {
            return $document;
        }

        $this->indexDocument($document, true);

        return $document->fresh();
    }

    private function indexDocument(KnowledgeDocument $document, bool $replace = false): void
    {
        if (($document->content_clean ?? '') === '') {
            $document->update([
                'status' => 'failed',
                'error_message' => 'Document content is empty after cleaning.',
            ]);
            return;
        }

        $document->update(['status' => 'chunked']);

        if ($replace) {
            KnowledgeChunk::query()->where('knowledge_document_id', $document->id)->delete();
        }

        $chunks = $this->chunkingService->chunk($document->content_clean);

        foreach ($chunks as $index => $text) {
            $chunk = KnowledgeChunk::query()->create([
                'tenant_id' => $document->tenant_id,
                'knowledge_document_id' => $document->id,
                'chunk_index' => $index,
                'chunk_text' => $text,
                'metadata_json' => [
                    'title' => $document->title,
                    'type' => $document->type,
                    'language' => $document->language,
                    'tags' => $document->tags_json,
                ],
            ]);

            $this->embeddingService->embed($chunk);
        }

        $document->update(['status' => 'indexed']);
    }
}
