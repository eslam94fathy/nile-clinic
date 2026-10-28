<?php

use Illuminate\Support\Facades\Route;

// Loaded by SchedulingServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    // Nested under the doctor (research R1). Reads for any active staff member, changes for
    // administrators, /doctors/me/… for doctors (SchedulePolicy, TimeOffPolicy). The /doctors/me/…
    // routes are declared before the whereNumber('doctor') group; {doctor} and {timeOff} are
    // plain ids loaded through the repositories.
});
