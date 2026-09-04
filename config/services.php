<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent LLM (grounded conversation reasoning)
    |--------------------------------------------------------------------------
    |
    | Provider-agnostic OpenAI-compatible endpoint used by AgentLlmService to
    | reason over persisted photo evidence during agent turns. Any provider
    | works (OpenRouter free tier by default, Groq, Google AI Studio, …).
    | Leaving AGENT_LLM_API_KEY empty disables LLM reasoning entirely and the
    | deterministic composer answers instead — the app never blocks on the
    | model being present.
    |
    */

    'agent_llm' => [
        'base_url' => env('AGENT_LLM_BASE_URL', 'https://openrouter.ai/api/v1'),
        'key' => env('AGENT_LLM_API_KEY'),
        // Default keeps the service enabled with the OpenRouter free-tier
        // model when only a key is configured — a model-less key previously
        // disabled reasoning entirely (AgentLlmService::DEFAULT_MODEL was
        // unreachable dead code).
        'model' => env('AGENT_LLM_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
        'timeout' => (int) env('AGENT_LLM_TIMEOUT', 20),
        // Multimodal agent turns: when true and the bound model supports
        // vision, the agent LLM also SEES thumbnails of the top candidate
        // frames instead of reasoning over JSON labels alone.
        'vision' => (bool) env('AGENT_LLM_VISION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vision analysis provider (photo observations)
    |--------------------------------------------------------------------------
    |
    | Production photo analysis: an OpenAI-compatible vision-language model
    | (OpenRouter by default, sharing the agent_llm key unless overridden)
    | turns real uploaded pixels into structured technical + creative
    | observations. Server-side validation coerces the model's JSON against
    | the PhotoObservation contract; failures fall back to the deterministic
    | GD pixel-statistics provider with honest provenance either way.
    |
    | Leaving VLM_API_KEY (or AGENT_LLM_API_KEY) empty disables the VLM
    | entirely — analysis then uses only on-device pixel statistics.
    |
    */

    'vlm' => [
        'base_url' => env('VLM_BASE_URL', env('AGENT_LLM_BASE_URL', 'https://openrouter.ai/api/v1')),
        'key' => env('VLM_API_KEY', env('AGENT_LLM_API_KEY')),
        'model' => env('VLM_MODEL', 'google/gemini-2.5-flash'),
        'timeout' => (int) env('VLM_TIMEOUT', 25),
    ],

];
