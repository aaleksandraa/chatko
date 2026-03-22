<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'source_type',
        'source_connection_id',
        'external_id',
        'sku',
        'slug',
        'name',
        'short_description',
        'long_description',
        'price',
        'sale_price',
        'currency',
        'stock_qty',
        'in_stock',
        'availability_label',
        'product_url',
        'primary_image_url',
        'category_text',
        'brand_text',
        'attributes_json',
        'specs_json',
        'tags_json',
        'status',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'in_stock' => 'boolean',
            'attributes_json' => 'array',
            'specs_json' => 'array',
            'tags_json' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    public function embedding(): HasOne
    {
        return $this->hasOne(ProductEmbedding::class);
    }
}
