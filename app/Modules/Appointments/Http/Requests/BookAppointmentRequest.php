<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Http\Requests\Concerns\ValidatesBookableDate;
use App\Modules\Appointments\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BookAppointmentRequest extends FormRequest
{
    use ValidatesBookableDate;

    private const FIELDS = ['patient_id', 'doctor_id', 'date', 'start', 'note'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Appointment::class);
    }

    /**
     * The note is trimmed; an empty note is no note (research R11).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note'))) {
            $note = trim($this->input('note'));
            $this->merge(['note' => $note === '' ? null : $note]);
        }
    }

    /**
     * Unknown patients and doctors are reported by the service, after its locks (research R3).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'min:1'],
            'doctor_id' => ['required', 'integer', 'min:1'],
            ...$this->bookableDateRules(),
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_combine(
            self::FIELDS,
            array_map(fn (string $key) => __("appointments::attributes.{$key}"), self::FIELDS),
        );
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBookableDate($validator)];
    }
}
