<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Staff\Http\Requests\SignInRequest;
use App\Modules\Staff\Http\Resources\SignInResource;
use App\Modules\Staff\Http\Resources\StaffMemberResource;
use App\Modules\Staff\Services\AuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sign-in and the signed-in staff member's own account (US1).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
    ) {}

    public function signIn(SignInRequest $request): SignInResource
    {
        return SignInResource::make($this->authentication->signIn(
            $request->validated('email'),
            $request->validated('password'),
            $request->ip(),
        ));
    }

    public function signOut(Request $request): Response
    {
        $this->authentication->signOut($request->user());

        return response()->noContent();
    }

    public function me(Request $request): StaffMemberResource
    {
        return StaffMemberResource::make($request->user());
    }
}
