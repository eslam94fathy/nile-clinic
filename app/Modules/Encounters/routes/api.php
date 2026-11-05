<?php

use App\Modules\Encounters\Http\Controllers\EncounterController;
use App\Modules\Encounters\Http\Controllers\OwnVisitController;
use App\Modules\Encounters\Http\Controllers\PatientEncounterController;
use Illuminate\Support\Facades\Route;

// Loaded by EncountersServiceProvider with the "api" middleware group and the "api/v1" prefix.
// Every route is for doctors, under /doctors/me (research R1). Ids are plain ids loaded through the
// repositories.

Route::middleware('auth:sanctum')->prefix('doctors/me')->group(function () {
    Route::get('/visits', [OwnVisitController::class, 'index'])->name('encounters.visits');

    Route::whereNumber('visit')->group(function () {
        Route::post('/visits/{visit}/encounter', [OwnVisitController::class, 'start'])->name('encounters.start');
    });

    Route::whereNumber('encounter')->group(function () {
        Route::get('/encounters/{encounter}', [EncounterController::class, 'show'])->name('encounters.show');
        Route::put('/encounters/{encounter}', [EncounterController::class, 'update'])->name('encounters.update');
        Route::post('/encounters/{encounter}/finalize', [EncounterController::class, 'finalize'])->name('encounters.finalize');
        Route::post('/encounters/{encounter}/addenda', [EncounterController::class, 'addAddendum'])->name('encounters.addenda');
    });

    Route::whereNumber('patient')->group(function () {
        Route::get('/patients/{patient}/encounters', [PatientEncounterController::class, 'index'])->name('encounters.history');
    });
});
