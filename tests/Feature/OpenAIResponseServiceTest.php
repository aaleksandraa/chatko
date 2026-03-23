<?php

namespace Tests\Feature;

use App\Services\AI\OpenAIResponseService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIResponseServiceTest extends TestCase
{
    public function test_it_returns_openai_error_when_live_mode_is_enabled_and_api_key_is_missing(): void
    {
        config()->set('services.openai.api_key', null);
        config()->set('services.openai.force_live_responses', true);

        $result = app(OpenAIResponseService::class)->respond([
            'system' => 'Ti si AI prodajni asistent.',
            'developer' => 'Vrati trazeni JSON format.',
            'user' => 'Treba mi nesto za suhu kozu.',
            'context' => ['products' => []],
        ], 'gpt-5-mini-2025-08-07', 0.3, 350);

        $this->assertSame('openai_error', data_get($result, '_usage.source'));
        $this->assertSame('ai_unavailable', $result['detected_intent']);
        $this->assertSame([], $result['recommended_product_ids']);
    }

    public function test_it_retries_with_compatibility_payload_after_structured_400_error(): void
    {
        config()->set('services.openai.api_key', 'sk-test-key');

        Http::fakeSequence()
            ->push([
                'error' => [
                    'message' => 'Unsupported parameter: temperature',
                    'type' => 'invalid_request_error',
                ],
            ], 400)
            ->push([
                'output' => [
                    [
                        'type' => 'reasoning',
                        'content' => [],
                    ],
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => json_encode([
                                    'answer_text' => 'Predlazem dvije opcije za tvoj upit.',
                                    'recommended_product_ids' => [11, 12],
                                    'cta_type' => 'product_link',
                                    'cta_label' => 'Otvori proizvod',
                                    'needs_handoff' => false,
                                    'lead_capture_suggested' => false,
                                    'detected_intent' => 'product_recommendation',
                                    'confidence' => 0.84,
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 18,
                    'output_tokens' => 42,
                    'total_tokens' => 60,
                ],
            ], 200);

        $result = app(OpenAIResponseService::class)->respond([
            'system' => 'Ti si AI prodajni asistent.',
            'developer' => 'Vrati trazeni JSON format.',
            'user' => 'Treba mi nesto za suhu kozu.',
            'context' => [
                'products' => [
                    ['id' => 11, 'name' => 'Hydra serum'],
                    ['id' => 12, 'name' => 'Repair maska'],
                ],
            ],
        ], 'gpt-5-mini-2025-08-07', 0.3, 350);

        $this->assertSame('openai_compat', data_get($result, '_usage.source'));
        $this->assertSame('Predlazem dvije opcije za tvoj upit.', $result['answer_text']);
        $this->assertSame([11, 12], $result['recommended_product_ids']);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        $firstPayload = $recorded[0][0]->data();
        $secondPayload = $recorded[1][0]->data();

        $this->assertArrayHasKey('temperature', $firstPayload);
        $this->assertArrayHasKey('text', $firstPayload);
        $this->assertArrayNotHasKey('temperature', $secondPayload);
        $this->assertArrayNotHasKey('text', $secondPayload);
    }

    public function test_it_can_parse_json_from_markdown_fenced_output_text(): void
    {
        config()->set('services.openai.api_key', 'sk-test-key');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => "```json\n".json_encode([
                    'answer_text' => 'Nemam tacan proizvod, ali mogu ponuditi alternativu.',
                    'recommended_product_ids' => [],
                    'cta_type' => 'clarify_need',
                    'cta_label' => 'Pokazi alternative',
                    'needs_handoff' => false,
                    'lead_capture_suggested' => true,
                    'detected_intent' => 'no_match',
                    'confidence' => 0.42,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```",
                'usage' => [
                    'input_tokens' => 12,
                    'output_tokens' => 24,
                    'total_tokens' => 36,
                ],
            ], 200),
        ]);

        $result = app(OpenAIResponseService::class)->respond([
            'system' => 'Ti si AI prodajni asistent.',
            'developer' => 'Vrati trazeni JSON format.',
            'user' => 'Imate li ulje za bradu?',
            'context' => ['products' => []],
        ], 'gpt-5-mini-2025-08-07', 0.3, 350);

        $this->assertSame('openai', data_get($result, '_usage.source'));
        $this->assertSame('no_match', $result['detected_intent']);
        $this->assertSame([], $result['recommended_product_ids']);
    }
}
