<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'source_type',
        'title',
        'type',
        'language',
        'visibility',
        'ai_allowed',
        'internal_only',
        'status',
        'version',
        'original_file_path',
        'mime_type',
        'source_ref',
        'tags_json',
        'content_raw',
        'content_clean',
        'error_message',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'ai_allowed' => 'boolean',
            'internal_only' => 'boolean',
            'tags_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}
