<?php

use App\Modules\Appointments\Http\Controllers\AppointmentController;
use Illuminate\Support\Facades\Route;

// Loaded by AppointmentsServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    // Top-level /appointments for administrators and receptionists; /doctors/me/appointments for
    // doctors (research R1, R8). {appointment} is a plain id loaded through the repository.
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
});
