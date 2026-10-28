<?php

use App\Modules\Scheduling\Http\Controllers\OwnScheduleController;
use App\Modules\Scheduling\Http\Controllers\ScheduleController;
use App\Modules\Scheduling\Http\Controllers\SlotController;
use App\Modules\Scheduling\Http\Controllers\TimeOffController;
use Illuminate\Support\Facades\Route;

// Loaded by SchedulingServiceProvider with the "api" middleware group and the "api/v1" prefix.

Route::middleware('auth:sanctum')->group(function () {
    // Nested under the doctor (research R1). Reads for any active staff member, changes for
    // administrators, /doctors/me/… for doctors (SchedulePolicy, TimeOffPolicy). The /doctors/me/…
    // routes are declared before the whereNumber('doctor') group; {doctor} and {timeOff} are
    // plain ids loaded through the repositories.
    Route::get('/doctors/me/schedule', [OwnScheduleController::class, 'schedule'])->name('scheduling.me.schedule');
    Route::get('/doctors/me/slots', [OwnScheduleController::class, 'slots'])->name('scheduling.me.slots');
    Route::get('/doctors/me/time-off', [OwnScheduleController::class, 'timeOff'])->name('scheduling.me.time-off');

    Route::whereNumber('doctor')->group(function () {
        Route::get('/doctors/{doctor}/schedule', [ScheduleController::class, 'show'])->name('scheduling.schedule.show');
        Route::put('/doctors/{doctor}/schedule', [ScheduleController::class, 'update'])->name('scheduling.schedule.update');
        Route::delete('/doctors/{doctor}/schedule', [ScheduleController::class, 'destroy'])->name('scheduling.schedule.destroy');
        Route::get('/doctors/{doctor}/slots', [SlotController::class, 'index'])->name('scheduling.slots.index');
        Route::get('/doctors/{doctor}/time-off', [TimeOffController::class, 'index'])->name('scheduling.time-off.index');
        Route::post('/doctors/{doctor}/time-off', [TimeOffController::class, 'store'])->name('scheduling.time-off.store');
        Route::delete('/doctors/{doctor}/time-off/{timeOff}', [TimeOffController::class, 'destroy'])
            ->whereNumber('timeOff')
            ->name('scheduling.time-off.destroy');
    });
});
