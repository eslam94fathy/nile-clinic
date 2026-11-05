<?php

use App\Modules\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Loaded by ReportsServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/reports/money-collected', [ReportController::class, 'moneyCollected'])->name('reports.money-collected');
    Route::get('/reports/billing', [ReportController::class, 'billing'])->name('reports.billing');
    Route::get('/reports/activity', [ReportController::class, 'activity'])->name('reports.activity');
});
