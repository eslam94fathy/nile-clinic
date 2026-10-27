<?php

namespace App\Modules\Staff\Http\Resources;

use App\Modules\Staff\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The sign-in result: the plain-text token (shown only here) and the account.
 *
 * @property array{token: string, user: User} $resource
 */
class SignInResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource['token'],
            'token_type' => 'Bearer',
            'user' => StaffMemberResource::make($this->resource['user']),
        ];
    }
}
