<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Http\Requests\SetScheduleRequest;
use App\Modules\Scheduling\Http\Resources\ScheduleResource;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Services\ScheduleService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Staff read a doctor's weekly schedule; administrators set and clear it (US1).
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleService $schedules,
    ) {}

    public function show(int $doctor): ScheduleResource
    {
        Gate::authorize('view', DoctorSchedule::class);

        return ScheduleResource::make($this->schedules->show($doctor));
    }

    public function update(SetScheduleRequest $request, int $doctor): ScheduleResource
    {
        return ScheduleResource::make($this->schedules->replace(
            $doctor,
            (int) $request->validated('appointment_minutes'),
            $request->periodsInMinutes(),
        ));
    }

    public function destroy(int $doctor): Response
    {
        Gate::authorize('update', DoctorSchedule::class);

        $this->schedules->clear($doctor);

        return response()->noContent();
    }
}
