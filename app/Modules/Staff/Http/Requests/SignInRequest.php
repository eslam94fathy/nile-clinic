<?php

namespace App\Modules\Staff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

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
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ASCII only: the email collation would otherwise match look-alike letters to an
            // account, letting variants dodge the per-email rate limit (research R7).
            'email' => ['required', 'string', 'ascii', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
