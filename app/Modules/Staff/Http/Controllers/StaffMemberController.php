<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Staff\Http\Requests\ListStaffRequest;
use App\Modules\Staff\Http\Requests\StoreStaffMemberRequest;
use App\Modules\Staff\Http\Requests\UpdateStaffMemberRequest;
use App\Modules\Staff\Http\Resources\StaffMemberResource;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\StaffManagementService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Administrators list, create, view, and edit staff accounts (US3).
 */
class StaffMemberController extends Controller
{
    public function __construct(
        private readonly StaffManagementService $staff,
    ) {}

    public function index(ListStaffRequest $request): AnonymousResourceCollection
    {
        return StaffMemberResource::collection($this->staff->list(
            $request->roleFilter(),
            $request->activeFilter(),
            $request->perPage(),
        ));
    }

    public function store(StoreStaffMemberRequest $request): StaffMemberResource
    {
        return StaffMemberResource::make($this->staff->create($request->validated()));
    }

    public function show(int $staffMember): StaffMemberResource
    {
        Gate::authorize('view', User::class);

        return StaffMemberResource::make($this->staff->find($staffMember));
    }

    public function update(UpdateStaffMemberRequest $request, int $staffMember): StaffMemberResource
    {
        return StaffMemberResource::make($this->staff->update(
            $this->staff->find($staffMember),
            $request->validated(),
        ));
    }
}
