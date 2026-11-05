<?php

use App\Modules\Payments\Http\Controllers\ChargeController;
use App\Modules\Payments\Http\Controllers\FeeController;
use App\Modules\Payments\Http\Controllers\VisitChargeController;
use Illuminate\Support\Facades\Route;

// Loaded by PaymentsServiceProvider with the "api" middleware group and the "api/v1" prefix. Ids are
// plain ids loaded through the repositories.

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/fees', [FeeController::class, 'index'])->name('payments.fees');

    Route::whereNumber('doctor')->group(function () {
        Route::get('/doctors/{doctor}/fee', [FeeController::class, 'show'])->name('payments.fee');
        Route::put('/doctors/{doctor}/fee', [FeeController::class, 'update'])->name('payments.fee.set');
    });

    Route::get('/visits', [VisitChargeController::class, 'index'])->name('payments.visits');
    Route::get('/charges', [ChargeController::class, 'index'])->name('payments.charges');

    Route::whereNumber('visit')->group(function () {
        Route::post('/visits/{visit}/charge', [VisitChargeController::class, 'store'])->name('payments.charge');
    });

    Route::whereNumber('charge')->group(function () {
        Route::get('/charges/{charge}', [ChargeController::class, 'show'])->name('payments.charges.show');
        Route::post('/charges/{charge}/payments', [ChargeController::class, 'pay'])->name('payments.charges.pay');
        Route::post('/charges/{charge}/refunds', [ChargeController::class, 'refund'])->name('payments.charges.refund');
        Route::put('/charges/{charge}/discount', [ChargeController::class, 'discount'])->name('payments.charges.discount');
        Route::post('/charges/{charge}/cancel', [ChargeController::class, 'cancel'])->name('payments.charges.cancel');
    });
});
