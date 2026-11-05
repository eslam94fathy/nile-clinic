<?php

use App\Modules\Queues\Http\Controllers\CheckInController;
use Illuminate\Support\Facades\Route;

// Loaded by QueuesServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    // Check-in belongs to the appointment but creates a queue entry, so this module owns the route
    // (research R1). Ids are plain ids loaded through the repositories.
    Route::whereNumber('appointment')->group(function () {
        Route::post('/appointments/{appointment}/check-in', CheckInController::class)->name('queues.check-in');
    });
});
