<?php

use Illuminate\Support\Facades\Route;

// Loaded by EncountersServiceProvider with the "api" middleware group and the "api/v1" prefix.
// Every route is for doctors, under /doctors/me (research R1). Ids are plain ids loaded through the
// repositories.

Route::middleware('auth:sanctum')->group(function () {
    //
});
