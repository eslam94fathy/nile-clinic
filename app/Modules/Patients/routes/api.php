<?php

use App\Modules\Patients\Http\Controllers\PatientController;
use Illuminate\Support\Facades\Route;

// Loaded by PatientsServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    // Administrators and receptionists only (PatientPolicy). {patient} is a plain id loaded
    // through the repository, not route-model binding; routes using it go in a
    // Route::whereNumber('patient') group.
    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
    Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');

    Route::whereNumber('patient')->group(function () {
        Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
    });
});
