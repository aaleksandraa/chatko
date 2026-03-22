<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessCsvImportJob;
use App\Models\ImportJob;
use App\Models\Product;
use App\Services\Audit\AuditLogService;
use App\Services\Catalog\ProductUpsertService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $query = Product::query()->where('tenant_id', $tenant->id);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('category_text', 'like', '%'.$search.'%');
            });
        }

        $perPage = max(1, min((int) $request->query('per_page', 25), 200));

        $products = $query->latest('id')->paginate($perPage);

        return response()->json($products);
    }

    public function store(Request $request, TenantContext $tenantContext, ProductUpsertService $productUpsertService): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'external_id' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'long_description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'stock_qty' => ['nullable', 'integer'],
            'in_stock' => ['nullable', 'boolean'],
            'availability_label' => ['nullable', 'string', 'max:255'],
            'product_url' => ['nullable', 'url'],
            'primary_image_url' => ['nullable', 'url'],
            'category_text' => ['nullable', 'string', 'max:255'],
            'brand_text' => ['nullable', 'string', 'max:255'],
            'attributes_json' => ['nullable', 'array'],
            'specs_json' => ['nullable', 'array'],
            'tags_json' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $productUpsertService->upsert($tenant, $payload, 'manual', null);

        if (! $result['ok']) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $result['errors'],
            ], 422);
        }

        return response()->json(['data' => $result['product']], 201);
    }

    public function update(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $product = Product::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        $payload = $request->validate([
            'sku' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['sometimes', 'nullable', 'string'],
            'long_description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:8'],
            'stock_qty' => ['sometimes', 'nullable', 'integer'],
            'in_stock' => ['sometimes', 'boolean'],
            'availability_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'product_url' => ['sometimes', 'nullable', 'url'],
            'primary_image_url' => ['sometimes', 'nullable', 'url'],
            'category_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brand_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attributes_json' => ['sometimes', 'nullable', 'array'],
            'specs_json' => ['sometimes', 'nullable', 'array'],
            'tags_json' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', 'string', 'max:32'],
        ]);

        $before = $product->toArray();
        $product->fill($payload);
        $product->save();
        $auditLogService->logMutation(
            $request,
            'updated',
            $product,
            $before,
            $product->fresh()?->toArray(),
            ['changed_fields' => array_keys($payload)],
        );

        return response()->json(['data' => $product]);
    }

    public function importCsv(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $path = $payload['file']->store("imports/{$tenant->id}", 'local');
        $absolutePath = Storage::disk('local')->path($path);

        $job = ImportJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => 'csv_import',
            'source_type' => 'csv',
            'status' => 'pending',
            'triggered_by' => null,
            'log_summary' => 'CSV import queued.',
        ]);

        ProcessCsvImportJob::dispatch($job->id, $absolutePath);

        return response()->json([
            'message' => 'CSV import queued.',
            'data' => $job,
        ], 202);
    }

    public function destroy(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $product = Product::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $before = $product->toArray();
        $product->delete();
        $auditLogService->logMutation(
            $request,
            'deleted',
            $product,
            $before,
            null,
        );

        return response()->json([
            'message' => 'Product deleted.',
        ]);
    }

    public function destroyAll(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $query = Product::query()->where('tenant_id', $tenant->id);
        $total = (int) (clone $query)->count();

        if ($total <= 0) {
            return response()->json([
                'message' => 'No products to delete.',
                'data' => [
                    'deleted_count' => 0,
                ],
            ]);
        }

        $sampleIds = (clone $query)
            ->latest('id')
            ->limit(50)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $deletedCount = 0;

        DB::transaction(function () use ($tenant, &$deletedCount): void {
            $deletedCount = Product::query()
                ->where('tenant_id', $tenant->id)
                ->delete();
        });

        $auditLogService->logMutation(
            $request,
            'bulk_deleted',
            'products',
            [
                'tenant_id' => (int) $tenant->id,
                'count' => $total,
                'sample_ids' => $sampleIds,
            ],
            [
                'tenant_id' => (int) $tenant->id,
                'deleted_count' => (int) $deletedCount,
            ],
            [
                'scope' => 'tenant_products',
                'deleted_count' => (int) $deletedCount,
            ],
        );

        return response()->json([
            'message' => 'All products deleted.',
            'data' => [
                'deleted_count' => (int) $deletedCount,
            ],
        ]);
    }
}
