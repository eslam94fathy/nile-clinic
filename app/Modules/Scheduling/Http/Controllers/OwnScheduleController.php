<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Scheduling\Http\Requests\ListSlotsRequest;
use App\Modules\Scheduling\Http\Requests\ListTimeOffRequest;
use App\Modules\Scheduling\Http\Resources\ScheduleResource;
use App\Modules\Scheduling\Http\Resources\SlotResource;
use App\Modules\Scheduling\Http\Resources\TimeOffResource;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Services\ScheduleService;
use App\Modules\Scheduling\Services\SlotService;
use App\Modules\Scheduling\Services\TimeOffService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A signed-in doctor reads their own schedule, slots, and time off (US4, FR-018). The doctor is
 * resolved from the account; a doctor without a profile gets 404.
 */
class OwnScheduleController extends Controller
{
    public function __construct(
        private readonly DoctorService $doctors,
        private readonly ScheduleService $schedules,
        private readonly SlotService $slots,
        private readonly TimeOffService $timeOff,
    ) {}

    public function schedule(Request $request): ScheduleResource
    {
        Gate::authorize('viewOwn', DoctorSchedule::class);

        return ScheduleResource::make($this->schedules->show($this->ownDoctorId($request)));
    }

    public function slots(ListSlotsRequest $request): AnonymousResourceCollection
    {
        return SlotResource::collection($this->slots->forDoctor(
            $this->ownDoctorId($request),
            $request->validated('from'),
            $request->validated('to'),
        ));
    }

    public function timeOff(ListTimeOffRequest $request): AnonymousResourceCollection
    {
        return TimeOffResource::collection($this->timeOff->list(
            $this->ownDoctorId($request),
            $request->validated('from'),
            $request->validated('to'),
        ));
    }

    private function ownDoctorId(Request $request): int
    {
        return $this->doctors->findOwn($request->user())->id;
    }
}
