<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'provider',
        'model_name',
        'embedding_model',
        'temperature',
        'max_output_tokens',
        'max_messages_monthly',
        'max_tokens_daily',
        'max_tokens_monthly',
        'block_on_limit',
        'alert_on_limit',
        'top_p',
        'safety_rules_json',
        'system_prompt_template',
        'sales_rules_json',
    ];

    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:2',
            'top_p' => 'decimal:2',
            'block_on_limit' => 'boolean',
            'alert_on_limit' => 'boolean',
            'safety_rules_json' => 'array',
            'sales_rules_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
