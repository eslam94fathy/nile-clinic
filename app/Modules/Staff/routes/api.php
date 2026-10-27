<?php

use App\Modules\Staff\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Loaded by StaffServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::post('/auth/sign-in', [AuthController::class, 'signIn'])->name('staff.auth.sign-in');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/sign-out', [AuthController::class, 'signOut'])->name('staff.auth.sign-out');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('staff.auth.me');
});
