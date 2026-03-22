<?php

namespace App\Services\AI;

use App\Models\AiConfig;
use App\Models\Tenant;

class PromptBuilderService
{
    /**
     * @param array<string, mixed> $context
     */
    public function build(Tenant $tenant, ?AiConfig $config, string $userMessage, array $context): array
    {
        $system = $this->resolveSystemPrompt($tenant, $config);
        $responseMaxChars = max(120, (int) config('services.openai.response_max_chars', 520));

        $rules = [
            'Vrati JSON objekat sa poljima: answer_text, recommended_product_ids, cta_type, cta_label, needs_handoff, lead_capture_suggested, detected_intent, confidence.',
            'recommended_product_ids mora sadrzavati samo ID-jeve iz liste proizvoda u kontekstu.',
            'Ako nema dobrog fit-a, vrati praznu listu recommended_product_ids.',
            "answer_text drzi kratkim: maksimalno {$responseMaxChars} karaktera i najvise 3 recenice.",
        ];

        return [
            'system' => $system,
            'developer' => implode("\n", $rules),
            'user' => $userMessage,
            'context' => $context,
        ];
    }

    private function resolveSystemPrompt(Tenant $tenant, ?AiConfig $config): string
    {
        $template = trim((string) ($config?->system_prompt_template ?? ''));

        if ($template === '') {
            return <<<TXT
Ti si AI prodajni asistent za {$tenant->name}.
Koristi samo dostavljeni kontekst (proizvodi + knowledge).
Ne izmisljaj cijene, zalihe, akcije ni uslove.
Ako nema podatka, reci da podatak nije dostupan i postavi kratko podpitanje.
Odgovori kratko, prirodno i prodajno korisno.
TXT;
        }

        $replacements = [
            '{{tenant_name}}' => (string) $tenant->name,
            '{{tenant_slug}}' => (string) $tenant->slug,
            '{{tenant_locale}}' => (string) $tenant->locale,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
