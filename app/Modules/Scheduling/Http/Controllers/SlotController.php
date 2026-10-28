<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Http\Requests\ListSlotsRequest;
use App\Modules\Scheduling\Http\Resources\SlotResource;
use App\Modules\Scheduling\Services\SlotService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff list a doctor's bookable slots (US2).
 */
class SlotController extends Controller
{
    public function __construct(
        private readonly SlotService $slots,
    ) {}

    public function index(ListSlotsRequest $request, int $doctor): AnonymousResourceCollection
    {
        return SlotResource::collection($this->slots->forDoctor(
            $doctor,
            $request->validated('from'),
            $request->validated('to'),
        ));
    }
}
