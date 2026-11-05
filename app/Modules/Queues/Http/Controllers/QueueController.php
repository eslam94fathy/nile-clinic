<?php

namespace App\Modules\Queues\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Queues\Http\Requests\AddWalkInRequest;
use App\Modules\Queues\Http\Requests\ListQueueRequest;
use App\Modules\Queues\Http\Resources\QueueEntryResource;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Administrators and receptionists see the waiting lists for today (US2) and add walk-ins (US4).
 */
class QueueController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
    ) {}

    public function index(ListQueueRequest $request): AnonymousResourceCollection
    {
        return QueueEntryResource::collection($this->queue->list($request->validated('status'), $request->perPage()));
    }

    public function forDoctor(int $doctor): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', QueueEntry::class);

        return QueueEntryResource::collection($this->queue->listForDoctor($doctor));
    }

    public function walkIn(AddWalkInRequest $request): JsonResponse
    {
        return QueueEntryResource::make($this->queue->addWalkIn(
            $request->user(),
            (int) $request->validated('patient_id'),
            (int) $request->validated('doctor_id'),
        ))->response()->setStatusCode(201);
    }
}
