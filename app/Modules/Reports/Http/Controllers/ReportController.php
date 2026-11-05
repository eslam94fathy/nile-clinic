<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Http\Requests\ReportRequest;
use App\Modules\Reports\Http\Resources\ActivityRowResource;
use App\Modules\Reports\Http\Resources\BillingRowResource;
use App\Modules\Reports\Http\Resources\MoneyCollectedResource;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only summaries for administrators.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
    ) {}

    public function moneyCollected(ReportRequest $request): MoneyCollectedResource
    {
        return MoneyCollectedResource::make($this->reports->moneyCollected($request->from(), $request->to(), $request->doctorId()));
    }

    public function billing(ReportRequest $request): AnonymousResourceCollection
    {
        $report = $this->reports->billing($request->from(), $request->to(), $request->doctorId(), $request->perPage());

        return BillingRowResource::collection($report['rows'])->additional(['totals' => $report['totals']]);
    }

    public function activity(ReportRequest $request): AnonymousResourceCollection
    {
        $report = $this->reports->activity($request->from(), $request->to(), $request->doctorId(), $request->perPage());

        return ActivityRowResource::collection($report['rows'])->additional(['totals' => $report['totals']]);
    }
}
