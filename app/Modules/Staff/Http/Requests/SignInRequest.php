<?php

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Support\EmailRules;
use Illuminate\Foundation\Http\FormRequest;

class SignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Emails are stored lower-cased, so match them the same way (research R9).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => EmailRules::normalize($this->input('email'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', ...EmailRules::rules()],
            'password' => ['required', 'string'],
        ];
    }
}
