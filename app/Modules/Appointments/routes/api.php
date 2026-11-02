<?php

use App\Modules\Appointments\Http\Controllers\AppointmentActionController;
use App\Modules\Appointments\Http\Controllers\AppointmentController;
use App\Modules\Appointments\Http\Controllers\OwnAppointmentController;
use Illuminate\Support\Facades\Route;

// Loaded by AppointmentsServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    // Top-level /appointments for administrators and receptionists; /doctors/me/appointments for
    // doctors (research R1, R8). {appointment} is a plain id loaded through the repository.
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');

    Route::get('/doctors/me/appointments', [OwnAppointmentController::class, 'index'])->name('appointments.me.index');

    Route::whereNumber('appointment')->group(function () {
        Route::get('/doctors/me/appointments/{appointment}', [OwnAppointmentController::class, 'show'])->name('appointments.me.show');
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::post('/appointments/{appointment}/reschedule', [AppointmentActionController::class, 'reschedule'])->name('appointments.reschedule');
        Route::post('/appointments/{appointment}/cancel', [AppointmentActionController::class, 'cancel'])->name('appointments.cancel');
    });
});
