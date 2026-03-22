<?php

namespace App\Services\Conversation;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;

class AnalyticsService
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function track(Conversation $conversation, string $eventName, ?float $value = null, array $metadata = []): AnalyticsEvent
    {
        return AnalyticsEvent::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'visitor_uuid' => $conversation->visitor_uuid,
            'event_name' => $eventName,
            'event_value' => $value,
            'metadata_json' => $metadata,
        ]);
    }
}
