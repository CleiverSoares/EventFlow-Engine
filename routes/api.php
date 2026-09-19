<?php

use App\Http\Controllers\Api\LeadIngestionController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\MetricsController;
use App\Http\Middleware\AuthenticateTenantApiKey;
use App\Http\Middleware\EnforceTenantRateLimit;
use App\Http\Middleware\RecordHttpMetrics;
use Illuminate\Support\Facades\Route;

Route::get('/metrics', MetricsController::class);

Route::middleware([
    RecordHttpMetrics::class,
    AuthenticateTenantApiKey::class,
    EnforceTenantRateLimit::class,
])->group(function (): void {
    Route::get('/ping', PingController::class);
    Route::post('/leads', [LeadIngestionController::class, 'store']);
});
