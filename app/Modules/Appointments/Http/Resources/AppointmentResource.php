<?php

namespace App\Modules\Appointments\Http\Resources;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Staff\Models\User;
use Illuminate\Http\Request;

/**
 * One appointment for staff (FR-010): the summary plus the note and who booked, rescheduled, or
 * changed its status, and when.
 *
 * @mixin Appointment
 */
class AppointmentResource extends AppointmentSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'note' => $this->note,
            'booked_by' => $this->staff($this->bookedBy),
            'booked_at' => $this->created_at?->toISOString(),
            'rescheduled_by' => $this->staff($this->rescheduledBy),
            'rescheduled_at' => $this->rescheduled_at?->toISOString(),
            'status_changed_by' => $this->staff($this->statusChangedBy),
            'status_changed_at' => $this->status_changed_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function staff(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
