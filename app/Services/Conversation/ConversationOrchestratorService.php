<?php

namespace App\Services\Conversation;

use App\Models\AiConfig;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Models\Widget;
use App\Services\AI\EmbeddingGenerationService;
use App\Services\AI\OpenAIModelCatalogService;
use App\Services\AI\OpenAIResponseService;
use App\Services\AI\PromptBuilderService;
use App\Services\AI\ResponseValidationService;
use App\Services\Retrieval\KnowledgeRetrievalService;
use App\Services\Retrieval\ProductRetrievalService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConversationOrchestratorService
{
    public function __construct(
        private readonly IntentDetectionService $intentDetectionService,
        private readonly EntityExtractionService $entityExtractionService,
        private readonly ProductRetrievalService $productRetrievalService,
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
        private readonly SalesDecisionService $salesDecisionService,
        private readonly CheckoutConversationService $checkoutConversationService,
        private readonly PromptBuilderService $promptBuilderService,
        private readonly OpenAIResponseService $openAIResponseService,
        private readonly ResponseValidationService $responseValidationService,
        private readonly OpenAIModelCatalogService $openAIModelCatalogService,
        private readonly AnalyticsService $analyticsService,
        private readonly TenantUsageLimitService $tenantUsageLimitService,
        private readonly EmbeddingGenerationService $embeddingGenerationService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleMessage(Tenant $tenant, Widget $widget, array $payload): array
    {
        $conversation = $this->resolveConversation($tenant, $widget, $payload);
        $messageText = trim((string) ($payload['message'] ?? ''));
        $config = AiConfig::query()->where('tenant_id', $tenant->id)->first();

        $preLimitSnapshot = $this->tenantUsageLimitService->evaluateBeforeResponse($tenant, $config);
        if ((bool) ($preLimitSnapshot['blocked'] ?? false)) {
            $blocking = is_array($preLimitSnapshot['blocking'] ?? null) ? $preLimitSnapshot['blocking'] : [];
            $answer = $this->tenantUsageLimitService->blockedMessage($blocking);

            $assistantMessage = ConversationMessage::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'message_text' => $answer,
                'intent' => 'usage_limit',
                'metadata_json' => [
                    'usage_limit' => $blocking,
                    'limits' => $preLimitSnapshot['limits'] ?? [],
                    'usage' => $preLimitSnapshot['usage'] ?? [],
                ],
            ]);

            $this->analyticsService->track($conversation, 'usage_limit_blocked', null, [
                'assistant_message_id' => $assistantMessage->id,
                'limit_type' => $blocking['type'] ?? null,
                'current' => $blocking['current'] ?? null,
                'limit' => $blocking['limit'] ?? null,
            ]);

            $this->tenantUsageLimitService->sendAlertsForExceeded($tenant, $conversation, $preLimitSnapshot);

            return [
                'conversation_id' => $conversation->id,
                'session_id' => $conversation->session_id,
                'answer_text' => $answer,
                'detected_intent' => 'usage_limit',
                'recommended_products' => [],
                'cta' => [
                    'type' => 'contact_support',
                    'label' => 'Kontaktirajte podrsku',
                ],
                'lead_capture_suggested' => false,
                'needs_handoff' => true,
                'checkout' => null,
                'order' => null,
                'usage_limit' => [
                    'blocked' => true,
                    'type' => $blocking['type'] ?? null,
                    'current' => $blocking['current'] ?? null,
                    'limit' => $blocking['limit'] ?? null,
                ],
            ];
        }

        ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'message_text' => $messageText,
            'normalized_text' => mb_strtolower($messageText),
        ]);

        $intent = $this->intentDetectionService->detect($messageText);
        $entities = $this->entityExtractionService->extract($messageText);
        $queryEmbedding = $this->embeddingGenerationService->embedText(
            $messageText,
            $this->resolvedEmbeddingModel($config),
        );

        $products = $this->productRetrievalService->search($tenant, $messageText, $entities, 5, $queryEmbedding);
        $allowedProductIds = $products->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // Checkout flow is deterministic and should bypass LLM calls to reduce cost.
        $checkoutFlow = $this->checkoutConversationService->handleMessage(
            $conversation,
            $messageText,
            $intent,
            $products,
        );

        if (is_array($checkoutFlow)) {
            $checkoutAnswer = trim((string) ($checkoutFlow['answer_text'] ?? ''));
            if ($checkoutAnswer === '') {
                $checkoutAnswer = 'Nastavljamo checkout korak.';
            }

            $preparedCheckoutResponse = [
                'answer_text' => $checkoutAnswer,
                'recommended_product_ids' => array_slice($allowedProductIds, 0, 3),
                'cta_type' => 'checkout',
                'cta_label' => 'Nastavi checkout',
                'needs_handoff' => false,
                'lead_capture_suggested' => false,
                'detected_intent' => $intent,
                'confidence' => 0.98,
                '_usage' => [
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'source' => 'checkout_rule_engine',
                ],
            ];

            $validated = $this->responseValidationService->validate($preparedCheckoutResponse, $allowedProductIds);
            $usage = $this->tenantUsageLimitService->usageFromLlmResponse($preparedCheckoutResponse);
            $responseSource = 'checkout_rule_engine';
        } else {
            $knowledgeChunks = $this->knowledgeRetrievalService->search($tenant, $messageText, 4, $queryEmbedding);

            $salesDecision = $this->salesDecisionService->decide(
                $intent,
                $entities,
                $products->map(fn ($product) => $product->toArray())->all(),
            );

            $promptPackage = $this->promptBuilderService->build($tenant, $config, $messageText, [
                'intent' => $intent,
                'entities' => $entities,
                'sales_decision' => $salesDecision,
                'products' => $products->map(fn ($product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
                    'currency' => $product->currency,
                    'in_stock' => $product->in_stock,
                    'url' => $product->product_url,
                    'reason_hint' => $product->short_description,
                ])->all(),
                'knowledge_chunks' => $knowledgeChunks->map(fn ($chunk): array => [
                    'id' => $chunk->id,
                    'text' => $chunk->chunk_text,
                    'meta' => $chunk->metadata_json,
                ])->all(),
            ]);

            $llmResponse = $this->resolveLlmResponseWithCache(
                $tenant,
                $promptPackage,
                $this->resolvedModelName($config),
                $this->resolvedTemperature($config),
                $this->resolvedMaxOutputTokens($config),
                $intent,
                $messageText,
            );

            $validated = $this->responseValidationService->validate($llmResponse, $allowedProductIds);
            $usage = $this->tenantUsageLimitService->usageFromLlmResponse($llmResponse);
            $responseSource = (string) data_get($llmResponse, '_usage.source', 'openai');
        }

        if (is_array($checkoutFlow)) {
            $this->analyticsService->track($conversation, 'checkout_step', null, [
                'status' => data_get($checkoutFlow, 'checkout.status'),
                'missing_fields' => data_get($checkoutFlow, 'checkout.missing_fields', []),
            ]);

            if (data_get($checkoutFlow, 'order.external_order_id')) {
                $this->analyticsService->track($conversation, 'checkout_order_placed', (float) data_get($checkoutFlow, 'order.total', 0), [
                    'external_order_id' => data_get($checkoutFlow, 'order.external_order_id'),
                    'integration_type' => data_get($checkoutFlow, 'order.integration_type'),
                    'payment_required' => (bool) data_get($checkoutFlow, 'order.payment_required', false),
                ]);
            }
        }

        $assistantMessage = ConversationMessage::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message_text' => $validated['answer_text'],
            'intent' => $intent,
            'metadata_json' => [
                'entities' => $entities,
                'recommended_product_ids' => $validated['recommended_product_ids'],
                'confidence' => $validated['confidence'],
                'cta_type' => $validated['cta_type'],
                'checkout' => is_array($checkoutFlow) ? ($checkoutFlow['checkout'] ?? null) : null,
                'response_source' => $responseSource,
            ],
            'tokens_input' => $usage['input_tokens'],
            'tokens_output' => $usage['output_tokens'],
        ]);

        $this->analyticsService->track($conversation, 'message_processed', null, [
            'intent' => $intent,
            'recommended_count' => count($validated['recommended_product_ids']),
            'assistant_message_id' => $assistantMessage->id,
            'response_source' => $responseSource,
        ]);

        if ($validated['recommended_product_ids'] !== []) {
            $this->analyticsService->track($conversation, 'product_recommended', null, [
                'product_ids' => $validated['recommended_product_ids'],
            ]);
        }

        $postLimitSnapshot = $this->tenantUsageLimitService->snapshot($tenant, $config);
        if ((array) ($postLimitSnapshot['exceeded'] ?? []) !== []) {
            $this->tenantUsageLimitService->sendAlertsForExceeded($tenant, $conversation, $postLimitSnapshot);
        }

        return [
            'conversation_id' => $conversation->id,
            'session_id' => $conversation->session_id,
            'answer_text' => $validated['answer_text'],
            'detected_intent' => $validated['detected_intent'] ?: $intent,
            'recommended_products' => $products
                ->whereIn('id', $validated['recommended_product_ids'])
                ->values()
                ->map(fn ($product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'currency' => $product->currency,
                    'url' => $product->product_url,
                    'image_url' => $product->primary_image_url,
                    'in_stock' => $product->in_stock,
                ])
                ->all(),
            'cta' => [
                'type' => $validated['cta_type'],
                'label' => $validated['cta_label'],
            ],
            'lead_capture_suggested' => $validated['lead_capture_suggested'],
            'needs_handoff' => $validated['needs_handoff'],
            'checkout' => is_array($checkoutFlow) ? ($checkoutFlow['checkout'] ?? null) : null,
            'order' => is_array($checkoutFlow) ? ($checkoutFlow['order'] ?? null) : null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveConversation(Tenant $tenant, Widget $widget, array $payload): Conversation
    {
        $conversationId = $payload['conversation_id'] ?? null;

        if ($conversationId !== null) {
            $existing = Conversation::query()
                ->where('tenant_id', $tenant->id)
                ->where('widget_id', $widget->id)
                ->where('id', $conversationId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'widget_id' => $widget->id,
            'visitor_uuid' => $this->normalizedVisitorUuid($payload['visitor_uuid'] ?? null),
            'session_id' => $payload['session_id'] ?? (string) Str::uuid(),
            'channel' => $payload['channel'] ?? 'web_widget',
            'locale' => $payload['locale'] ?? $widget->default_locale,
            'started_at' => now(),
            'source_url' => $payload['source_url'] ?? null,
            'utm_json' => $payload['utm_json'] ?? null,
            'status' => 'active',
        ]);
    }

    /**
     * @param array<string, mixed> $promptPackage
     * @return array<string, mixed>
     */
    private function resolveLlmResponseWithCache(
        Tenant $tenant,
        array $promptPackage,
        string $modelName,
        float $temperature,
        int $maxOutputTokens,
        string $intent,
        string $messageText,
    ): array {
        if (! $this->shouldUseResponseCache($intent, $messageText)) {
            return $this->openAIResponseService->respond($promptPackage, $modelName, $temperature, $maxOutputTokens);
        }

        $cacheKey = $this->responseCacheKey($tenant, $promptPackage, $modelName, $temperature, $maxOutputTokens);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['answer_text'])) {
            $cached['_usage'] = [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'source' => 'cache',
            ];

            return $cached;
        }

        $response = $this->openAIResponseService->respond($promptPackage, $modelName, $temperature, $maxOutputTokens);

        if ($this->canStoreCachedResponse($response)) {
            Cache::put($cacheKey, $response, now()->addSeconds($this->responseCacheTtlSeconds()));
        }

        return $response;
    }

    private function shouldUseResponseCache(string $intent, string $messageText): bool
    {
        if (! (bool) config('services.openai.response_cache_enabled', true)) {
            return false;
        }

        if ($this->isCheckoutIntent($intent)) {
            return false;
        }

        $maxChars = max(20, (int) config('services.openai.response_cache_max_message_chars', 220));
        if (mb_strlen($messageText) > $maxChars) {
            return false;
        }

        // Avoid caching messages likely to contain personal/contact data.
        if (preg_match('/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/iu', $messageText) === 1) {
            return false;
        }

        if (preg_match('/(\+?\d[\d\s\-\/]{6,}\d)/u', $messageText) === 1) {
            return false;
        }

        if (preg_match('/\b(adresa|ulica|postanski|telefon|broj)\b/iu', $messageText) === 1) {
            return false;
        }

        return true;
    }

    private function canStoreCachedResponse(array $response): bool
    {
        $answerText = trim((string) ($response['answer_text'] ?? ''));
        $source = (string) data_get($response, '_usage.source', '');

        return $answerText !== '' && $source === 'openai';
    }

    /**
     * @param array<string, mixed> $promptPackage
     */
    private function responseCacheKey(
        Tenant $tenant,
        array $promptPackage,
        string $modelName,
        float $temperature,
        int $maxOutputTokens,
    ): string {
        $payload = [
            'model' => $modelName,
            'temperature' => $temperature,
            'max_output_tokens' => $maxOutputTokens,
            'prompt' => $promptPackage,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            $encoded = serialize($payload);
        }

        return sprintf('chat:llm:tenant:%d:%s', $tenant->id, sha1($encoded));
    }

    private function responseCacheTtlSeconds(): int
    {
        return max(60, (int) config('services.openai.response_cache_ttl_seconds', 21600));
    }

    private function isCheckoutIntent(string $intent): bool
    {
        return in_array($intent, ['checkout_ready', 'add_to_cart_ready'], true);
    }

    private function resolvedModelName(?AiConfig $config): string
    {
        $modelName = trim((string) ($config?->model_name ?? ''));
        $candidate = $modelName !== '' ? $modelName : (string) config('services.openai.default_model', 'gpt-5-mini');

        return $this->openAIModelCatalogService->normalizeChatModel($candidate);
    }

    private function resolvedEmbeddingModel(?AiConfig $config): string
    {
        $embeddingModel = trim((string) ($config?->embedding_model ?? ''));
        $candidate = $embeddingModel !== '' ? $embeddingModel : (string) config('services.openai.embedding_model', 'text-embedding-3-small');

        return $this->openAIModelCatalogService->normalizeEmbeddingModel($candidate);
    }

    private function resolvedTemperature(?AiConfig $config): float
    {
        return $config?->temperature !== null ? (float) $config->temperature : 0.3;
    }

    private function resolvedMaxOutputTokens(?AiConfig $config): int
    {
        $default = max(64, (int) config('services.openai.default_max_output_tokens', 350));

        return (int) ($config?->max_output_tokens ?? $default);
    }

    private function normalizedVisitorUuid(mixed $raw): string
    {
        $candidate = trim((string) ($raw ?? ''));
        if (Str::isUuid($candidate)) {
            return strtolower($candidate);
        }

        return (string) Str::uuid();
    }
}
