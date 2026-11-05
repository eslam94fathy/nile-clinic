<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Http\Requests\ReportRequest;
use App\Modules\Reports\Http\Resources\MoneyCollectedResource;
use App\Modules\Reports\Services\ReportService;

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
}
