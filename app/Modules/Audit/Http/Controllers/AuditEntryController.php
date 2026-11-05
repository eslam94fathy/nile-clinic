<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Http\Requests\SearchAuditEntriesRequest;
use App\Modules\Audit\Http\Resources\AuditEntryResource;
use App\Modules\Audit\Services\AuditSearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Administrators search the trail (US1–US3). There is nothing else to do with it (FR-014).
 */
class AuditEntryController extends Controller
{
    public function __construct(
        private readonly AuditSearchService $entries,
    ) {}

    public function index(SearchAuditEntriesRequest $request): AnonymousResourceCollection
    {
        return AuditEntryResource::collection($this->entries->search($request->filters(), $request->perPage()));
    }
}
