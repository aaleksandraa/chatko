<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'integration_connection_id',
        'conversation_id',
        'order_attributed_id',
        'external_order_id',
        'provider_status',
        'normalized_status',
        'tracking_url',
        'message_text',
        'payload_json',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'occurred_at' => 'datetime',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function orderAttributed(): BelongsTo
    {
        return $this->belongsTo(OrderAttributed::class);
    }
}
