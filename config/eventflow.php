<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Runtime mode
    |--------------------------------------------------------------------------
    |
    | phase1 = synchronous ingest/enrich/dispatch (chaos baseline for README)
    | phase2 = transactional outbox + RabbitMQ + Redis patterns
    |
    */

    'mode' => env('EVENTFLOW_MODE', 'phase1'),

    /*
    |--------------------------------------------------------------------------
    | Tenant API authentication
    |--------------------------------------------------------------------------
    */

    'auth' => [
        'header' => env('EVENTFLOW_API_KEY_HEADER', 'X-Api-Key'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan rate limits (requests per minute) — enforced via Redis in Phase 2
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'basic' => (int) env('EVENTFLOW_RATE_LIMIT_BASIC', 60),
        'pro' => (int) env('EVENTFLOW_RATE_LIMIT_PRO', 300),
        'enterprise' => (int) env('EVENTFLOW_RATE_LIMIT_ENTERPRISE', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis role key prefixes (four distinct uses)
    |--------------------------------------------------------------------------
    */

    'redis' => [
        'cache_prefix' => env('EVENTFLOW_REDIS_CACHE_PREFIX', 'eventflow:enrichment:'),
        'lock_prefix' => env('EVENTFLOW_REDIS_LOCK_PREFIX', 'eventflow:lock:'),
        'rate_prefix' => env('EVENTFLOW_REDIS_RATE_PREFIX', 'eventflow:rate:'),
        'geo_key' => env('EVENTFLOW_REDIS_GEO_KEY', 'eventflow:leads:geo'),
        'enrichment_ttl_seconds' => (int) env('EVENTFLOW_ENRICHMENT_TTL', 86400),
        'lock_ttl_seconds' => (int) env('EVENTFLOW_LOCK_TTL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ
    |--------------------------------------------------------------------------
    */

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'eventflow'),
        'password' => env('RABBITMQ_PASSWORD', 'eventflow'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'queue' => env('RABBITMQ_QUEUE', 'leads.incoming'),
        'retry_queue' => env('RABBITMQ_RETRY_QUEUE', 'leads.incoming.retry'),
        'dlq' => env('RABBITMQ_DLQ', 'leads.incoming.dlq'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enrichment (prefer real BrasilAPI)
    |--------------------------------------------------------------------------
    */

    'enrichment' => [
        'base_url' => env('EVENTFLOW_ENRICHMENT_BASE_URL', 'https://brasilapi.com.br/api'),
        'timeout_seconds' => (int) env('EVENTFLOW_ENRICHMENT_TIMEOUT', 5),
        'token' => env('EVENTFLOW_ENRICHMENT_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Destination webhook
    |--------------------------------------------------------------------------
    */

    'webhook' => [
        'url' => env('EVENTFLOW_WEBHOOK_URL', 'http://127.0.0.1:8090/webhook'),
        'timeout_seconds' => (int) env('EVENTFLOW_WEBHOOK_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker defaults
    |--------------------------------------------------------------------------
    */

    'circuit_breaker' => [
        'failure_threshold' => (int) env('EVENTFLOW_CB_FAILURE_THRESHOLD', 5),
        'recovery_timeout_seconds' => (int) env('EVENTFLOW_CB_RECOVERY_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox relay
    |--------------------------------------------------------------------------
    */

    'outbox' => [
        'max_attempts' => (int) env('EVENTFLOW_OUTBOX_MAX_ATTEMPTS', 5),
        'batch_size' => (int) env('EVENTFLOW_OUTBOX_BATCH_SIZE', 100),
    ],

];
