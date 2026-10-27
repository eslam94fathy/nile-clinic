<?php

namespace App\Modules\Staff\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The one definition of the staff password rule (FR-022): 12–72 characters, confirmed.
 */
class PasswordRules
{
    /**
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', Password::min(12)->max(72), 'confirmed'];
    }
}
