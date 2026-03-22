<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationCheckout extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'status',
        'items_json',
        'customer_first_name',
        'customer_last_name',
        'customer_name',
        'customer_email',
        'customer_phone',
        'delivery_address',
        'delivery_city',
        'delivery_postal_code',
        'delivery_country',
        'customer_note',
        'payment_method',
        'estimated_total',
        'currency',
        'external_order_id',
        'external_checkout_url',
        'external_response_json',
        'submitted_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'items_json' => 'array',
            'external_response_json' => 'array',
            'submitted_at' => 'datetime',
            'estimated_total' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
