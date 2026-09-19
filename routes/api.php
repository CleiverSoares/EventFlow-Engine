<?php

use App\Http\Controllers\Api\LeadIngestionController;
use App\Http\Controllers\Api\PingController;
use App\Http\Middleware\AuthenticateTenantApiKey;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateTenantApiKey::class)->group(function (): void {
    Route::get('/ping', PingController::class);
    Route::post('/leads', [LeadIngestionController::class, 'store']);
});
