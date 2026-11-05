<?php

namespace App\Modules\Queues\Http\Resources;

use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Scheduling\Support\ClockTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doctor's own shape of a queue entry (FR-010, research R8): the patient only as {id, full_name,
 * sex, date_of_birth}; no phone, booking note, national ID, address, or emergency contact, and no
 * doctor (it is the reader).
 *
 * @mixin QueueEntry
 */
class OwnQueueEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'queue_number' => $this->queue_number,
            'status' => $this->status->value,
            'walk_in' => $this->appointment_id === null,
            'arrived_at' => $this->arrived_at->toISOString(),
            'called_at' => $this->called_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'left_at' => $this->left_at?->toISOString(),
            'appointment' => $this->appointment === null ? null : [
                'id' => $this->appointment->id,
                'start' => ClockTime::fromMinutes($this->appointment->start_minute),
                'end' => ClockTime::fromMinutes($this->appointment->end_minute),
            ],
            'patient' => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'sex' => $this->patient->sex->value,
                'date_of_birth' => $this->patient->date_of_birth->toDateString(),
            ],
        ];
    }
}
