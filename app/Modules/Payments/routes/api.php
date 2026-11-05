<?php

use App\Modules\Payments\Http\Controllers\FeeController;
use Illuminate\Support\Facades\Route;

// Loaded by PaymentsServiceProvider with the "api" middleware group and the "api/v1" prefix. Ids are
// plain ids loaded through the repositories.

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/fees', [FeeController::class, 'index'])->name('payments.fees');

    Route::whereNumber('doctor')->group(function () {
        Route::get('/doctors/{doctor}/fee', [FeeController::class, 'show'])->name('payments.fee');
        Route::put('/doctors/{doctor}/fee', [FeeController::class, 'update'])->name('payments.fee.set');
    });
});
