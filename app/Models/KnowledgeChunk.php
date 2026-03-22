<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'knowledge_document_id',
        'chunk_index',
        'chunk_text',
        'metadata_json',
        'embedding_model',
        'embedding_vector',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'embedding_vector' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
