<?php

namespace App\Modules\Encounters\Http\Requests;

use App\Modules\Encounters\Models\Encounter;
use Illuminate\Foundation\Http\FormRequest;

class SaveEncounterRequest extends FormRequest
{
    private const LINE_FIELDS = ['medication', 'dosage', 'frequency', 'duration', 'instructions'];

    public function authorize(): bool
    {
        return $this->user()->can('access', Encounter::class);
    }

    /**
     * A save replaces all content, so every key must be sent: a client that forgets one gets 422
     * rather than erasing it (research R8). Messages never repeat what was submitted (research R6).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'chief_complaint' => ['present', 'nullable', 'string', 'max:10000'],
            'examination_findings' => ['present', 'nullable', 'string', 'max:10000'],
            'diagnosis' => ['present', 'nullable', 'string', 'max:10000'],
            'treatment_plan' => ['present', 'nullable', 'string', 'max:10000'],
            'prescriptions' => ['present', 'array', 'max:20'],
            'prescriptions.*' => ['array'],
            'prescriptions.*.medication' => ['required', 'string', 'max:500'],
            'prescriptions.*.dosage' => ['nullable', 'string', 'max:500'],
            'prescriptions.*.frequency' => ['nullable', 'string', 'max:500'],
            'prescriptions.*.duration' => ['nullable', 'string', 'max:500'],
            'prescriptions.*.instructions' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (['chief_complaint', 'examination_findings', 'diagnosis', 'treatment_plan', 'prescriptions'] as $key) {
            $attributes[$key] = __("encounters::attributes.{$key}");
        }

        foreach (self::LINE_FIELDS as $field) {
            $attributes["prescriptions.*.{$field}"] = __("encounters::attributes.{$field}");
        }

        return $attributes;
    }

    /**
     * The content to store, each line with exactly its five keys.
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        $content = $this->validated();

        $content['prescriptions'] = array_map(
            fn (array $line) => array_merge(array_fill_keys(self::LINE_FIELDS, null), $line),
            array_values($content['prescriptions']),
        );

        return $content;
    }
}
