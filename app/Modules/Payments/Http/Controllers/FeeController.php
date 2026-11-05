<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Requests\ListFeesRequest;
use App\Modules\Payments\Http\Requests\SetFeeRequest;
use App\Modules\Payments\Http\Resources\DoctorFeeResource;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Services\FeeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Doctors' consultation fees (US1). Administrators and receptionists read them; only administrators
 * set them.
 */
class FeeController extends Controller
{
    public function __construct(
        private readonly FeeService $fees,
    ) {}

    public function index(ListFeesRequest $request): AnonymousResourceCollection
    {
        return DoctorFeeResource::collection($this->fees->list($request->perPage()));
    }

    public function show(int $doctor): DoctorFeeResource
    {
        Gate::authorize('viewAny', DoctorFee::class);

        return DoctorFeeResource::make($this->fees->find($doctor));
    }

    public function update(SetFeeRequest $request, int $doctor): DoctorFeeResource
    {
        return DoctorFeeResource::make($this->fees->set($request->user(), $doctor, $request->amount()));
    }
}
