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
        'retention_days' => (int) env('EVENTFLOW_OUTBOX_RETENTION_DAYS', 7),
        // 0 = disabled. Reject ingest with 503 when PENDING backlog exceeds these.
        'max_pending' => (int) env('EVENTFLOW_OUTBOX_MAX_PENDING', 0),
        'max_pending_per_tenant' => (int) env('EVENTFLOW_OUTBOX_MAX_PENDING_PER_TENANT', 0),
    ],

    'metrics' => [
        'token' => env('EVENTFLOW_METRICS_TOKEN', 'change-me-metrics-token'),
    ],

    'health' => [
        'check_redis' => filter_var(env('EVENTFLOW_HEALTH_CHECK_REDIS', true), FILTER_VALIDATE_BOOL),
        'check_rabbitmq' => filter_var(env('EVENTFLOW_HEALTH_CHECK_RABBITMQ', true), FILTER_VALIDATE_BOOL),
    ],

    'exports' => [
        'mode' => env('EVENTFLOW_EXPORT_MODE', 'async'),
        'chunk_size' => (int) env('EVENTFLOW_EXPORT_CHUNK_SIZE', 500),
        'max_processing_global' => (int) env('EVENTFLOW_EXPORT_MAX_PROCESSING_GLOBAL', 16),
        'max_processing_basic' => (int) env('EVENTFLOW_EXPORT_MAX_PROCESSING_BASIC', 3),
        'max_processing_pro' => (int) env('EVENTFLOW_EXPORT_MAX_PROCESSING_PRO', 5),
        'max_processing_enterprise' => (int) env('EVENTFLOW_EXPORT_MAX_PROCESSING_ENTERPRISE', 4),
        'wake_coalesce_ms' => (int) env('EVENTFLOW_EXPORT_WAKE_COALESCE_MS', 250),
        'broadcast_queue' => env('EVENTFLOW_EXPORT_BROADCAST_QUEUE', 'exports.broadcast'),
        'rabbitmq' => [
            'queue' => env('RABBITMQ_EXPORTS_QUEUE', 'exports.requested'),
            'retry_queue' => env('RABBITMQ_EXPORTS_RETRY_QUEUE', 'exports.requested.retry'),
            'retry_ttl_ms' => (int) env('RABBITMQ_EXPORTS_RETRY_TTL_MS', 3000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing (W3C Trace Context + OTLP HTTP → Jaeger)
    |--------------------------------------------------------------------------
    */

    'tracing' => [
        'enabled' => (bool) env('EVENTFLOW_TRACING_ENABLED', true),
        'otlp_endpoint' => env('EVENTFLOW_OTLP_ENDPOINT', 'http://127.0.0.1:4318/v1/traces'),
        'service_name' => env('EVENTFLOW_SERVICE_NAME', 'eventflow-engine'),
    ],

];
