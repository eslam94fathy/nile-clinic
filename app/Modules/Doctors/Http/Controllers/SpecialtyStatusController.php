<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Http\Resources\SpecialtyResource;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Services\SpecialtyService;
use Illuminate\Support\Facades\Gate;

/**
 * Administrators deactivate and reactivate specialties (FR-003, FR-008).
 */
class SpecialtyStatusController extends Controller
{
    public function __construct(
        private readonly SpecialtyService $specialties,
    ) {}

    public function deactivate(int $specialty): SpecialtyResource
    {
        Gate::authorize('deactivate', Specialty::class);

        return SpecialtyResource::make($this->specialties->deactivate($this->specialties->find($specialty)));
    }

    public function reactivate(int $specialty): SpecialtyResource
    {
        Gate::authorize('reactivate', Specialty::class);

        return SpecialtyResource::make($this->specialties->reactivate($this->specialties->find($specialty)));
    }
}
