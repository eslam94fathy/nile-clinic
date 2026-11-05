<?php

namespace App\Modules\Queues\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Queues\Http\Resources\OwnQueueEntryResource;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Services\DoctorQueueService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A signed-in doctor sees their own list, calls the next patient, and finishes the visit (US3). The
 * doctor is resolved from the account; a doctor without a profile gets 404.
 */
class OwnQueueController extends Controller
{
    public function __construct(
        private readonly DoctorQueueService $queue,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewOwn', QueueEntry::class);

        return OwnQueueEntryResource::collection($this->queue->listOwn($request->user()));
    }

    public function callNext(Request $request): OwnQueueEntryResource
    {
        Gate::authorize('updateOwn', QueueEntry::class);

        return OwnQueueEntryResource::make($this->queue->callNext($request->user()));
    }

    public function finish(Request $request): OwnQueueEntryResource
    {
        Gate::authorize('updateOwn', QueueEntry::class);

        return OwnQueueEntryResource::make($this->queue->finish($request->user()));
    }
}
