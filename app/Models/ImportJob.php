<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'integration_connection_id',
        'job_type',
        'source_type',
        'status',
        'total_records',
        'processed_records',
        'success_records',
        'failed_records',
        'skipped_records',
        'started_at',
        'finished_at',
        'triggered_by',
        'log_summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportJobRow::class);
    }
}
