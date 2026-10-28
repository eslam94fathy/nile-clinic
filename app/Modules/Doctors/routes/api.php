<?php

use App\Modules\Doctors\Http\Controllers\DoctorController;
use App\Modules\Doctors\Http\Controllers\SpecialtyController;
use App\Modules\Doctors\Http\Controllers\SpecialtyStatusController;
use Illuminate\Support\Facades\Route;

// Loaded by DoctorsServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    // Reads for any active staff member, changes for administrators (SpecialtyPolicy,
    // DoctorPolicy). {specialty} and {doctor} are plain ids loaded through the repositories, not
    // route-model binding; routes using them go in Route::whereNumber(...) groups.
    Route::get('/specialties', [SpecialtyController::class, 'index'])->name('specialties.index');
    Route::post('/specialties', [SpecialtyController::class, 'store'])->name('specialties.store');

    Route::whereNumber('specialty')->group(function () {
        Route::patch('/specialties/{specialty}', [SpecialtyController::class, 'update'])->name('specialties.update');
        Route::post('/specialties/{specialty}/deactivate', [SpecialtyStatusController::class, 'deactivate'])->name('specialties.deactivate');
        Route::post('/specialties/{specialty}/reactivate', [SpecialtyStatusController::class, 'reactivate'])->name('specialties.reactivate');
    });

    Route::post('/doctors', [DoctorController::class, 'store'])->name('doctors.store');

    Route::whereNumber('doctor')->group(function () {
        Route::patch('/doctors/{doctor}', [DoctorController::class, 'update'])->name('doctors.update');
    });
});
