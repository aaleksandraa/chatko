<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\Lead;

class LeadCaptureService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function capture(Conversation $conversation, array $payload): Lead
    {
        $lead = Lead::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'name' => $payload['name'] ?? null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'note' => $payload['note'] ?? null,
            'consent' => (bool) ($payload['consent'] ?? false),
            'lead_status' => $payload['lead_status'] ?? 'new',
        ]);

        $conversation->update(['lead_captured' => true]);

        return $lead;
    }
}
