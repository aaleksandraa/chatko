<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WidgetAbuseLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'widget_id',
        'public_key',
        'route',
        'http_method',
        'reason',
        'ip_address',
        'origin',
        'referer',
        'user_agent',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class);
    }
}

