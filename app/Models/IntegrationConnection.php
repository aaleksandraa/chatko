<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'type',
        'name',
        'status',
        'base_url',
        'credentials_encrypted',
        'auth_type',
        'config_json',
        'mapping_json',
        'sync_frequency',
        'last_tested_at',
        'last_sync_at',
        'last_error',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'mapping_json' => 'array',
            'last_tested_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function importJobs(): HasMany
    {
        return $this->hasMany(ImportJob::class);
    }

    public function mappingPresets(): HasMany
    {
        return $this->hasMany(SourceMappingPreset::class);
    }
}
