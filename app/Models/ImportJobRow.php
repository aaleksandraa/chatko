<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJobRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_job_id',
        'external_row_ref',
        'row_index',
        'raw_payload_json',
        'normalized_payload_json',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload_json' => 'array',
            'normalized_payload_json' => 'array',
        ];
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}
