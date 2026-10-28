<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Http\Requests\StoreDoctorRequest;
use App\Modules\Doctors\Http\Requests\UpdateDoctorRequest;
use App\Modules\Doctors\Http\Resources\DoctorResource;
use App\Modules\Doctors\Services\DoctorService;
use Illuminate\Http\JsonResponse;

/**
 * Administrators create and update doctor profiles (US2); all staff browse them (US3).
 */
class DoctorController extends Controller
{
    public function __construct(
        private readonly DoctorService $doctors,
    ) {}

    /**
     * The service returns the profile reloaded with its relations, so 201 is set explicitly.
     */
    public function store(StoreDoctorRequest $request): JsonResponse
    {
        return DoctorResource::make($this->doctors->create($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateDoctorRequest $request, int $doctor): DoctorResource
    {
        return DoctorResource::make($this->doctors->update(
            $this->doctors->find($doctor),
            $request->validated(),
        ));
    }
}
