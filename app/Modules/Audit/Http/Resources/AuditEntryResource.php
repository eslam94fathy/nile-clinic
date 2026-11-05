<?php

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Models\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry (contracts/audit.openapi.yaml). The name is the staff member's current one, and the role is
 * the one held at the time (FR-012). Patients appear by internal identifier only.
 *
 * @mixin AuditEntry
 */
class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->created_at->toISOString(),
            'action' => $this->action->value,
            'outcome' => $this->outcome->value,
            'actor' => [
                'type' => $this->actor_type->value,
                'id' => $this->actor_id,
                'name' => $this->actor?->name,
                'role' => $this->actor_role,
            ],
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type->value,
                'id' => $this->subject_id,
            ],
            'patient_id' => $this->patient_id,
            'network_address' => $this->network_address,
            'changes' => isset($this->details['fields']) ? [
                'fields' => $this->details['fields'],
                'values' => (object) ($this->details['values'] ?? []),
            ] : null,
            'filters' => isset($this->details['filters']) ? (object) $this->details['filters'] : null,
        ];
    }
}
