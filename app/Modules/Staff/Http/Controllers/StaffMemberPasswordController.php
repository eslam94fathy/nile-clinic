<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Staff\Http\Requests\SetStaffMemberPasswordRequest;
use App\Modules\Staff\Services\StaffManagementService;
use Illuminate\Http\Response;

/**
 * An administrator sets a new password for a staff member (FR-020).
 */
class StaffMemberPasswordController extends Controller
{
    public function __construct(
        private readonly StaffManagementService $staff,
    ) {}

    public function __invoke(SetStaffMemberPasswordRequest $request, int $staffMember): Response
    {
        $this->staff->setPassword($this->staff->find($staffMember), $request->validated('password'));

        return response()->noContent();
    }
}
