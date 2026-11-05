<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditActorType;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Services\AuditTrail;
use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * Who an entry names and from where (research R6).
 */
class AuditTrailTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    private function trail(): AuditTrail
    {
        return $this->app->make(AuditTrail::class);
    }

    public function test_a_signed_in_user_is_the_actor_with_their_role_and_address(): void
    {
        $user = User::factory()->receptionist()->create();
        Sanctum::actingAs($user);
        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']));

        $this->trail()->record(AuditAction::PatientOpened, AuditSubject::Patient, 5);

        $this->assertOneEntry(AuditAction::PatientOpened, [
            'actor_type' => AuditActorType::Staff,
            'actor_id' => $user->id,
            'actor_role' => 'receptionist',
            'outcome' => AuditOutcome::Succeeded,
            'subject_type' => AuditSubject::Patient,
            'subject_id' => 5,
            'network_address' => '203.0.113.7',
        ]);
    }

    public function test_a_later_role_change_leaves_the_role_in_the_entry(): void
    {
        $user = User::factory()->receptionist()->create();
        Sanctum::actingAs($user);

        $this->trail()->record(AuditAction::PatientOpened);
        $user->update(['role' => Role::Administrator]);

        $this->assertOneEntry(AuditAction::PatientOpened, ['actor_role' => 'receptionist']);
    }

    public function test_with_nobody_signed_in_a_record_is_the_system_with_no_address(): void
    {
        $this->trail()->record(AuditAction::StaffCreated, AuditSubject::StaffMember, 1);

        $this->assertOneEntry(AuditAction::StaffCreated, [
            'actor_type' => AuditActorType::System,
            'actor_id' => null,
            'actor_role' => null,
            'network_address' => null,
        ]);
    }

    public function test_an_explicit_actor_is_used(): void
    {
        $user = User::factory()->doctor()->create();

        $this->trail()->record(AuditAction::AuthSignIn, AuditSubject::StaffMember, $user->id, actor: $user);

        $this->assertOneEntry(AuditAction::AuthSignIn, ['actor_type' => AuditActorType::Staff, 'actor_id' => $user->id, 'actor_role' => 'doctor']);
    }

    public function test_details_are_stored_and_empty_details_are_null(): void
    {
        $this->trail()->record(AuditAction::PatientOpened);
        $this->trail()->record(AuditAction::AuthPasswordChanged, details: ['fields' => ['password']]);

        $this->assertOneEntry(AuditAction::PatientOpened, ['details' => null]);
        $this->assertOneEntry(AuditAction::AuthPasswordChanged, ['details' => ['fields' => ['password']]]);
    }

    public function test_record_changes_writes_nothing_when_nothing_changed(): void
    {
        $patient = Patient::factory()->create()->fresh();

        $this->trail()->recordChanges(AuditAction::PatientUpdated, $patient, AuditSubject::Patient);

        $this->assertNoEntries(AuditAction::PatientUpdated);
    }

    public function test_record_changes_adds_extra_fields_without_duplicates_and_can_name_another_subject(): void
    {
        $patient = Patient::factory()->create()->fresh();
        $patient->update(['phone' => '01000000098']);

        $this->trail()->recordChanges(AuditAction::PatientUpdated, $patient, AuditSubject::Patient, extraFields: ['phone', 'other'], subjectId: 99);

        $entry = $this->assertOneEntry(AuditAction::PatientUpdated, ['subject_id' => 99]);
        $this->assertEqualsCanonicalizing(['phone', 'other'], $entry->details['fields']);
    }

    public function test_extra_fields_alone_are_enough_to_write(): void
    {
        $patient = Patient::factory()->create()->fresh();

        $this->trail()->recordChanges(AuditAction::PatientUpdated, $patient, AuditSubject::Patient, extraFields: ['other']);

        $this->assertOneEntry(AuditAction::PatientUpdated);
    }

    public function test_a_refusal_with_nobody_signed_in_is_unknown_and_keeps_the_address(): void
    {
        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.8']));

        $this->trail()->recordRefusal(AuditAction::AuthSignIn);

        $this->assertOneEntry(AuditAction::AuthSignIn, [
            'actor_type' => AuditActorType::Unknown,
            'outcome' => AuditOutcome::Refused,
            'network_address' => '203.0.113.8',
        ]);
    }

    public function test_a_refusal_keeps_going_when_saving_fails(): void
    {
        $this->failAuditWrites();

        $this->trail()->recordRefusal(AuditAction::AuthSignIn);

        $this->assertNoEntries(AuditAction::AuthSignIn);
    }

    public function test_a_record_fails_when_saving_fails(): void
    {
        $this->failAuditWrites();

        $this->expectExceptionMessage('Forced audit failure.');

        $this->trail()->record(AuditAction::PatientOpened);
    }

    public function test_the_enums_match_the_contract(): void
    {
        $contract = file_get_contents(base_path('specs/011-audit-logs/contracts/audit.openapi.yaml'));

        preg_match_all('/^\s+- ([a-z_]+\.[a-z_]+)$/m', $contract, $matches);

        $this->assertCount(44, AuditAction::cases());
        $this->assertEqualsCanonicalizing(array_column(AuditAction::cases(), 'value'), $matches[1]);
    }
}
