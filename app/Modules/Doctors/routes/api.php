<?php

use Illuminate\Support\Facades\Route;

// Loaded by DoctorsServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    // Reads for any active staff member, changes for administrators (SpecialtyPolicy,
    // DoctorPolicy). {specialty} and {doctor} are plain ids loaded through the repositories, not
    // route-model binding; routes using them go in Route::whereNumber(...) groups.
});
