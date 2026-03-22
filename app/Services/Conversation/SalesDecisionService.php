<?php

namespace App\Services\Conversation;

class SalesDecisionService
{
    /**
     * @param array<string, mixed> $entities
     * @param array<int, array<string, mixed>> $products
     * @return array<string, mixed>
     */
    public function decide(string $intent, array $entities, array $products): array
    {
        $stage = 'discovery';

        if (in_array($intent, ['checkout_ready', 'add_to_cart_ready'], true)) {
            $stage = 'cta';
        } elseif ($intent === 'product_comparison') {
            $stage = 'argumentation';
        } elseif ($products === []) {
            $stage = 'recovery';
        } elseif (isset($entities['budget_max'])) {
            $stage = 'narrowing';
        }

        return [
            'stage' => $stage,
            'cta_preferred' => in_array($stage, ['narrowing', 'cta'], true),
            'lead_capture_suggested' => $products === [],
        ];
    }
}
