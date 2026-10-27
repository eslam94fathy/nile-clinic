<?php

use App\Modules\System\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Loaded by SystemServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::get('/health', HealthController::class)->name('system.health');
