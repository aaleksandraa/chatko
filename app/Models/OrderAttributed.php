<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderAttributed extends Model
{
    use HasFactory;

    protected $table = 'orders_attributed';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'external_order_id',
        'order_value',
        'currency',
        'attributed_model',
        'last_status',
        'last_status_at',
        'status_payload_json',
    ];

    protected function casts(): array
    {
        return [
            'order_value' => 'decimal:2',
            'last_status_at' => 'datetime',
            'status_payload_json' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class);
    }
}
