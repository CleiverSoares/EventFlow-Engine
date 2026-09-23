<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LeadIngestionController;
use App\Http\Controllers\Api\OutboxEventController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\MetricsController;
use App\Http\Middleware\AuthenticateMetricsToken;
use App\Http\Middleware\AuthenticateTenantApiKey;
use App\Http\Middleware\EnforceTenantRateLimit;
use App\Http\Middleware\PropagateTraceContext;
use App\Http\Middleware\RecordHttpMetrics;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::get('/metrics', MetricsController::class)
    ->middleware(AuthenticateMetricsToken::class);

Route::middleware([
    PropagateTraceContext::class,
    RecordHttpMetrics::class,
    AuthenticateTenantApiKey::class,
    EnforceTenantRateLimit::class,
])->group(function (): void {
    Route::get('/ping', PingController::class);
    Route::post('/leads', [LeadIngestionController::class, 'store']);
    Route::get('/outbox', [OutboxEventController::class, 'index']);
    Route::get('/outbox/{outbox}', [OutboxEventController::class, 'show']);
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);

    Route::post('/exports', [ExportController::class, 'store']);
    Route::get('/exports', [ExportController::class, 'index']);
    Route::get('/exports/{export}', [ExportController::class, 'show']);
    Route::get('/exports/{export}/download', [ExportController::class, 'download']);
});
