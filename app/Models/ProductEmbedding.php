<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductEmbedding extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'embedding_model',
        'embedded_text',
        'embedding_vector',
        'embedded_at',
    ];

    protected function casts(): array
    {
        return [
            'embedding_vector' => 'array',
            'embedded_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
