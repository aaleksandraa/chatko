<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Widget extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'public_key',
        'secret_key',
        'allowed_domains_json',
        'theme_json',
        'default_locale',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_domains_json' => 'array',
            'theme_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
