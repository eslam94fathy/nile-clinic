<?php

use App\Modules\Staff\Http\Controllers\AuthController;
use App\Modules\Staff\Http\Controllers\StaffMemberController;
use App\Modules\Staff\Http\Controllers\StaffMemberPasswordController;
use App\Modules\Staff\Http\Controllers\StaffMemberStatusController;
use Illuminate\Support\Facades\Route;

// Loaded by StaffServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::post('/auth/sign-in', [AuthController::class, 'signIn'])->name('staff.auth.sign-in');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/sign-out', [AuthController::class, 'signOut'])->name('staff.auth.sign-out');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('staff.auth.me');
    Route::put('/auth/password', [AuthController::class, 'changePassword'])->name('staff.auth.password');

    // Staff management (administrators only). {staffMember} is a plain id loaded through the
    // repository, not route-model binding (research R10).
    Route::get('/staff', [StaffMemberController::class, 'index'])->name('staff.members.index');
    Route::post('/staff', [StaffMemberController::class, 'store'])->name('staff.members.store');

    Route::whereNumber('staffMember')->group(function () {
        Route::get('/staff/{staffMember}', [StaffMemberController::class, 'show'])->name('staff.members.show');
        Route::patch('/staff/{staffMember}', [StaffMemberController::class, 'update'])->name('staff.members.update');
        Route::post('/staff/{staffMember}/deactivate', [StaffMemberStatusController::class, 'deactivate'])->name('staff.members.deactivate');
        Route::post('/staff/{staffMember}/reactivate', [StaffMemberStatusController::class, 'reactivate'])->name('staff.members.reactivate');
        Route::put('/staff/{staffMember}/password', StaffMemberPasswordController::class)->name('staff.members.password');
    });
});
