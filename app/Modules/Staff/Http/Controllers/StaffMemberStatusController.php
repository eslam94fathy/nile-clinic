<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Staff\Http\Resources\StaffMemberResource;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\StaffManagementService;
use Illuminate\Support\Facades\Gate;

/**
 * Administrators deactivate and reactivate staff accounts (FR-019).
 */
class StaffMemberStatusController extends Controller
{
    public function __construct(
        private readonly StaffManagementService $staff,
    ) {}

    public function deactivate(int $staffMember): StaffMemberResource
    {
        Gate::authorize('deactivate', User::class);

        return StaffMemberResource::make($this->staff->deactivate($this->staff->find($staffMember)));
    }

    public function reactivate(int $staffMember): StaffMemberResource
    {
        Gate::authorize('reactivate', User::class);

        return StaffMemberResource::make($this->staff->reactivate($this->staff->find($staffMember)));
    }
}
