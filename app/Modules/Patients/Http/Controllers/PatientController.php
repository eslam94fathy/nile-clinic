<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patients\Http\Requests\StorePatientRequest;
use App\Modules\Patients\Http\Resources\PatientResource;
use App\Modules\Patients\Services\PatientService;

/**
 * Administrators and receptionists register, find, view, and update patients (US1–US3).
 */
class PatientController extends Controller
{
    public function __construct(
        private readonly PatientService $patients,
    ) {}

    public function store(StorePatientRequest $request): PatientResource
    {
        return PatientResource::make($this->patients->register($request->validated()));
    }
}
