<?php

namespace App\Modules\Queues\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Queues\Http\Resources\QueueEntryResource;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Administrators and receptionists check in the patient of today's appointment (US1).
 */
class CheckInController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
    ) {}

    public function __invoke(Request $request, int $appointment): JsonResponse
    {
        Gate::authorize('create', QueueEntry::class);

        return QueueEntryResource::make($this->queue->checkIn($request->user(), $appointment))
            ->response()
            ->setStatusCode(201);
    }
}
