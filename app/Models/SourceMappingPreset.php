<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceMappingPreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'integration_connection_id',
        'name',
        'mapping_json',
    ];

    protected function casts(): array
    {
        return [
            'mapping_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }
}

