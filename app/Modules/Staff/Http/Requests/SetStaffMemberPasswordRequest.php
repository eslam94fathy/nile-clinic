<?php

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Models\User;
use App\Modules\Staff\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class SetStaffMemberPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setPassword', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => PasswordRules::rules(),
        ];
    }
}
