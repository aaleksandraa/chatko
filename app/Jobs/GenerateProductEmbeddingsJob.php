<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Catalog\ProductEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateProductEmbeddingsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $tenantId)
    {
    }

    public function handle(ProductEmbeddingService $embeddingService): void
    {
        Product::query()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'active')
            ->orderBy('id')
            ->chunk(100, function ($products) use ($embeddingService): void {
                foreach ($products as $product) {
                    $embeddingService->embed($product);
                }
            });
    }
}
