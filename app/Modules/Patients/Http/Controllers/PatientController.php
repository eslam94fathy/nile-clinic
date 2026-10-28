<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patients\Http\Requests\ListPatientsRequest;
use App\Modules\Patients\Http\Requests\StorePatientRequest;
use App\Modules\Patients\Http\Requests\UpdatePatientRequest;
use App\Modules\Patients\Http\Resources\PatientResource;
use App\Modules\Patients\Http\Resources\PatientSummaryResource;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Administrators and receptionists register, find, view, and update patients (US1–US3).
 */
class PatientController extends Controller
{
    public function __construct(
        private readonly PatientService $patients,
    ) {}

    public function index(ListPatientsRequest $request): AnonymousResourceCollection
    {
        return PatientSummaryResource::collection(
            $this->patients->list($request->filters(), $request->perPage()),
        );
    }

    public function store(StorePatientRequest $request): PatientResource
    {
        return PatientResource::make($this->patients->register($request->validated()));
    }

    public function show(int $patient): PatientResource
    {
        Gate::authorize('view', Patient::class);

        return PatientResource::make($this->patients->find($patient));
    }

    public function update(UpdatePatientRequest $request, int $patient): PatientResource
    {
        return PatientResource::make($this->patients->update(
            $this->patients->find($patient),
            $request->validated(),
        ));
    }
}
