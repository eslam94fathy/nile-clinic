<?php

namespace App\Modules\Queues\Http\Requests;

use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Foundation\Http\FormRequest;

class AddWalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', QueueEntry::class);
    }

    /**
     * Whether the patient and doctor exist is checked by QueueService (research R6).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'min:1'],
            'doctor_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'patient_id' => __('queues::attributes.patient_id'),
            'doctor_id' => __('queues::attributes.doctor_id'),
        ];
    }
}
