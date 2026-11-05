<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Requests\ListChargesRequest;
use App\Modules\Payments\Http\Requests\RecordPaymentRequest;
use App\Modules\Payments\Http\Requests\SetDiscountRequest;
use App\Modules\Payments\Http\Resources\ChargeResource;
use App\Modules\Payments\Http\Resources\ChargeSummaryResource;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Services\ChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Charges and the money on them (US2–US5). Doctors get 403 before any lookup; only administrators
 * discount and refund.
 */
class ChargeController extends Controller
{
    public function __construct(
        private readonly ChargeService $charges,
    ) {}

    public function index(ListChargesRequest $request): AnonymousResourceCollection
    {
        return ChargeSummaryResource::collection($this->charges->list($request->filters(), $request->perPage()));
    }

    public function show(int $charge): ChargeResource
    {
        Gate::authorize('viewAny', Charge::class);

        return ChargeResource::make($this->charges->find($charge));
    }

    public function pay(RecordPaymentRequest $request, int $charge): JsonResponse
    {
        return ChargeResource::make($this->charges->pay($request->user(), $charge, $request->amount(), $request->paymentMethod()))
            ->response()
            ->setStatusCode(201);
    }

    public function discount(SetDiscountRequest $request, int $charge): ChargeResource
    {
        return ChargeResource::make($this->charges->discount($request->user(), $charge, $request->amount(), $request->reason()));
    }
}
