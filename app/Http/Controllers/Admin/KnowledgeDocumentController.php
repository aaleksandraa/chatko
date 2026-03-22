<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Jobs\ReindexKnowledgeDocumentJob;
use App\Models\KnowledgeDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Knowledge\KnowledgeUploadService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeDocumentController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $query = KnowledgeDocument::query()->where('tenant_id', $tenant->id);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', (string) $request->query('type'));
        }

        $items = $query->latest('id')->paginate(20);

        return response()->json($items);
    }

    public function upload(Request $request, TenantContext $tenantContext, KnowledgeUploadService $knowledgeUploadService): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,txt'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'max:16'],
            'visibility' => ['nullable', 'string', 'max:64'],
            'ai_allowed' => ['nullable', 'boolean'],
            'internal_only' => ['nullable', 'boolean'],
            'tags_json' => ['nullable', 'array'],
        ]);

        $document = $knowledgeUploadService->uploadFile($tenant, $payload['file'], $payload);

        return response()->json(['data' => $document], 201);
    }

    public function storeText(Request $request, TenantContext $tenantContext, KnowledgeUploadService $knowledgeUploadService): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'max:16'],
            'visibility' => ['nullable', 'string', 'max:64'],
            'ai_allowed' => ['nullable', 'boolean'],
            'internal_only' => ['nullable', 'boolean'],
            'tags_json' => ['nullable', 'array'],
            'content_raw' => ['required', 'string', 'min:3'],
            'source_ref' => ['nullable', 'string', 'max:255'],
        ]);

        $document = $knowledgeUploadService->createTextDocument($tenant, $payload);

        return response()->json(['data' => $document], 201);
    }

    public function update(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $document = KnowledgeDocument::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        $payload = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:64'],
            'language' => ['sometimes', 'string', 'max:16'],
            'visibility' => ['sometimes', 'string', 'max:64'],
            'ai_allowed' => ['sometimes', 'boolean'],
            'internal_only' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'max:64'],
            'tags_json' => ['sometimes', 'nullable', 'array'],
            'content_raw' => ['sometimes', 'string'],
        ]);

        $before = $document->toArray();
        $document->fill($payload);
        $document->save();
        $auditLogService->logMutation(
            $request,
            'updated',
            $document,
            $before,
            $document->fresh()?->toArray(),
            ['changed_fields' => array_keys($payload)],
        );

        return response()->json(['data' => $document]);
    }

    public function reindex(Request $request, TenantContext $tenantContext, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $document = KnowledgeDocument::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        ReindexKnowledgeDocumentJob::dispatch($document->id);

        return response()->json([
            'message' => 'Reindex queued.',
            'data' => $document,
        ]);
    }

    public function destroy(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $document = KnowledgeDocument::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $before = $document->toArray();
        $document->delete();
        $auditLogService->logMutation(
            $request,
            'deleted',
            $document,
            $before,
            null,
        );

        return response()->json([
            'message' => 'Knowledge document deleted.',
        ]);
    }
}
