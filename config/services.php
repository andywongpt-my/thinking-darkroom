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
        'model' => env('AGENT_LLM_MODEL'),
        'timeout' => (int) env('AGENT_LLM_TIMEOUT', 20),
    ],

];
