<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'default_model' => env('OPENAI_MODEL', 'gpt-5-mini'),
        'allowed_chat_models' => array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) env(
                'OPENAI_ALLOWED_CHAT_MODELS',
                'gpt-5-mini,gpt-5-mini-2025-08-07,gpt-5.4-mini,gpt-5.4-mini-2026-03-17,gpt-4o-mini,gpt-4o-mini-tts,gpt-4o-mini-tts-2025-03-20',
            ))
        ))),
        'default_max_output_tokens' => (int) env('OPENAI_DEFAULT_MAX_OUTPUT_TOKENS', 350),
        'max_output_tokens_ceiling' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_CEILING', 1200),
        'response_max_chars' => (int) env('OPENAI_RESPONSE_MAX_CHARS', 520),
        'force_live_responses' => filter_var(env('OPENAI_FORCE_LIVE_RESPONSES', true), FILTER_VALIDATE_BOOL),
        'response_cache_enabled' => filter_var(env('OPENAI_RESPONSE_CACHE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'response_cache_ttl_seconds' => (int) env('OPENAI_RESPONSE_CACHE_TTL_SECONDS', 21600),
        'response_cache_max_message_chars' => (int) env('OPENAI_RESPONSE_CACHE_MAX_MESSAGE_CHARS', 220),
        'query_embedding_cache_enabled' => filter_var(env('OPENAI_QUERY_EMBED_CACHE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'query_embedding_cache_ttl_seconds' => (int) env('OPENAI_QUERY_EMBED_CACHE_TTL_SECONDS', 86400),
        'query_embedding_cache_max_chars' => (int) env('OPENAI_QUERY_EMBED_CACHE_MAX_CHARS', 280),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'allowed_embedding_models' => array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) env('OPENAI_ALLOWED_EMBEDDING_MODELS', 'text-embedding-3-small,text-embedding-ada-002'))
        ))),
        'embedding_fallback_models' => array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) env('OPENAI_EMBEDDING_FALLBACK_MODELS', 'text-embedding-3-small,text-embedding-ada-002'))
        ))),
        'embedding_dimensions' => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 1536),
        'rag' => [
            'knowledge_max_chunks' => (int) env('OPENAI_RAG_KNOWLEDGE_MAX_CHUNKS', 4),
            'knowledge_min_score' => (float) env('OPENAI_RAG_KNOWLEDGE_MIN_SCORE', 0.63),
            'knowledge_max_context_chars' => (int) env('OPENAI_RAG_KNOWLEDGE_MAX_CONTEXT_CHARS', 2600),
            'products_min_semantic_score' => (float) env('OPENAI_RAG_PRODUCTS_MIN_SEMANTIC_SCORE', 0.48),
        ],
    ],

    'widget' => [
        'session_token_ttl_seconds' => (int) env('WIDGET_SESSION_TOKEN_TTL_SECONDS', 86400),
        'allow_missing_origin' => filter_var(env('WIDGET_ALLOW_MISSING_ORIGIN', false), FILTER_VALIDATE_BOOL),
        'rate_limit' => [
            'config_per_minute' => (int) env('WIDGET_RATE_LIMIT_CONFIG_PER_MINUTE', 120),
            'session_start_per_minute' => (int) env('WIDGET_RATE_LIMIT_SESSION_START_PER_MINUTE', 20),
            'message_per_minute' => (int) env('WIDGET_RATE_LIMIT_MESSAGE_PER_MINUTE', 40),
            'checkout_per_minute' => (int) env('WIDGET_RATE_LIMIT_CHECKOUT_PER_MINUTE', 24),
            'lead_per_minute' => (int) env('WIDGET_RATE_LIMIT_LEAD_PER_MINUTE', 30),
            'event_per_minute' => (int) env('WIDGET_RATE_LIMIT_EVENT_PER_MINUTE', 90),
            'ip_per_minute' => (int) env('WIDGET_RATE_LIMIT_IP_PER_MINUTE', 240),
        ],
        'challenge' => [
            'enabled' => filter_var(env('WIDGET_CHALLENGE_ENABLED', false), FILTER_VALIDATE_BOOL),
            'provider' => env('WIDGET_CHALLENGE_PROVIDER', 'turnstile'),
            'action' => env('WIDGET_CHALLENGE_ACTION', 'widget_session_start'),
            'timeout_seconds' => (int) env('WIDGET_CHALLENGE_TIMEOUT_SECONDS', 6),
            'turnstile' => [
                'site_key' => env('TURNSTILE_SITE_KEY'),
                'secret_key' => env('TURNSTILE_SECRET_KEY'),
                'script_url' => env('TURNSTILE_SCRIPT_URL', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'),
                'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
            ],
            'hcaptcha' => [
                'site_key' => env('HCAPTCHA_SITE_KEY'),
                'secret_key' => env('HCAPTCHA_SECRET_KEY'),
                'script_url' => env('HCAPTCHA_SCRIPT_URL', 'https://js.hcaptcha.com/1/api.js?render=explicit'),
                'verify_url' => env('HCAPTCHA_VERIFY_URL', 'https://hcaptcha.com/siteverify'),
            ],
        ],
    ],

];
