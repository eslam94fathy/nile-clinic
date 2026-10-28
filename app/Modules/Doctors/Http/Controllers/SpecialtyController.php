<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Http\Requests\ListSpecialtiesRequest;
use App\Modules\Doctors\Http\Requests\StoreSpecialtyRequest;
use App\Modules\Doctors\Http\Requests\UpdateSpecialtyRequest;
use App\Modules\Doctors\Http\Resources\SpecialtyResource;
use App\Modules\Doctors\Services\SpecialtyService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff read the specialty catalog; administrators add and rename specialties (US1).
 */
class SpecialtyController extends Controller
{
    public function __construct(
        private readonly SpecialtyService $specialties,
    ) {}

    public function index(ListSpecialtiesRequest $request): AnonymousResourceCollection
    {
        return SpecialtyResource::collection($this->specialties->list($request->isActive()));
    }

    public function store(StoreSpecialtyRequest $request): SpecialtyResource
    {
        return SpecialtyResource::make($this->specialties->create($request->validated()));
    }

    public function update(UpdateSpecialtyRequest $request, int $specialty): SpecialtyResource
    {
        return SpecialtyResource::make($this->specialties->rename(
            $this->specialties->find($specialty),
            $request->validated(),
        ));
    }
}
