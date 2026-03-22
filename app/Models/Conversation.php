<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'widget_id',
        'visitor_uuid',
        'session_id',
        'channel',
        'locale',
        'started_at',
        'ended_at',
        'source_url',
        'utm_json',
        'status',
        'lead_captured',
        'converted',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'utm_json' => 'array',
            'lead_captured' => 'boolean',
            'converted' => 'boolean',
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

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function checkout(): HasOne
    {
        return $this->hasOne(ConversationCheckout::class);
    }

    public function ordersAttributed(): HasMany
    {
        return $this->hasMany(OrderAttributed::class);
    }
}
