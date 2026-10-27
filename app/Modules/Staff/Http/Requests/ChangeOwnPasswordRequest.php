<?php

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Any signed-in staff member may change their own password (FR-015, FR-023).
 */
class ChangeOwnPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Checked by AuthenticationService, which also rate-limits wrong guesses (FR-007).
            'current_password' => ['required', 'string'],
            'password' => PasswordRules::rules(),
        ];
    }
}
