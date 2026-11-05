<?php

use App\Modules\Queues\Http\Controllers\CheckInController;
use App\Modules\Queues\Http\Controllers\OwnQueueController;
use App\Modules\Queues\Http\Controllers\QueueController;
use Illuminate\Support\Facades\Route;

// Loaded by QueuesServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    // Check-in belongs to the appointment but creates a queue entry, so this module owns the route
    // (research R1). Ids are plain ids loaded through the repositories.
    Route::whereNumber('appointment')->group(function () {
        Route::post('/appointments/{appointment}/check-in', CheckInController::class)->name('queues.check-in');
    });

    Route::get('/queue', [QueueController::class, 'index'])->name('queues.index');

    // Doctors act on their own list and never send an entry id (research R1).
    Route::get('/doctors/me/queue', [OwnQueueController::class, 'index'])->name('queues.me.index');
    Route::post('/doctors/me/queue/call-next', [OwnQueueController::class, 'callNext'])->name('queues.me.call-next');
    Route::post('/doctors/me/queue/finish', [OwnQueueController::class, 'finish'])->name('queues.me.finish');

    Route::whereNumber('doctor')->group(function () {
        Route::get('/doctors/{doctor}/queue', [QueueController::class, 'forDoctor'])->name('queues.doctor');
    });
});
