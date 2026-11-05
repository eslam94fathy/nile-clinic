<?php

namespace App\Modules\Encounters\Http\Requests;

use App\Modules\Encounters\Models\Encounter;
use Illuminate\Foundation\Http\FormRequest;

class AddAddendumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('access', Encounter::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['text' => __('encounters::attributes.text')];
    }
}
