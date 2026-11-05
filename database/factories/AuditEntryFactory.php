<?php

namespace Database\Factories;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditActorType;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic audit entries for tests only (Constitution XII). Accepts created_at directly.
 *
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    protected $model = AuditEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => AuditAction::PatientUpdated,
            'outcome' => AuditOutcome::Succeeded,
            'actor_type' => AuditActorType::Staff,
            'actor_id' => User::factory()->receptionist(),
            'actor_role' => 'receptionist',
            'network_address' => '203.0.113.10',
        ];
    }
}
