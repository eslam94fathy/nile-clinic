<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditActorType;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * The first administrator is created outside the API, so the entry names the system (spec, Edge Cases,
 * research R6).
 */
class AuditCommandTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    private const PASSWORD = 'planted-admin-pass-1';

    public function test_creating_the_first_administrator_records_the_system_with_no_address(): void
    {
        $this->artisan('staff:create-admin', ['--name' => 'Synthetic Admin', '--email' => 'admin@clinic.test'])
            ->expectsQuestion(__('staff::messages.prompt_password'), self::PASSWORD)
            ->expectsQuestion(__('staff::messages.prompt_password_confirmation'), self::PASSWORD)
            ->assertSuccessful();

        $admin = User::query()->sole();

        $entry = $this->assertOneEntry(AuditAction::StaffCreated, [
            'outcome' => AuditOutcome::Succeeded,
            'actor_type' => AuditActorType::System,
            'actor_id' => null,
            'actor_role' => null,
            'network_address' => null,
            'subject_type' => AuditSubject::StaffMember,
            'subject_id' => $admin->id,
        ]);

        $this->assertEquals(['from' => null, 'to' => 'administrator'], $entry->details['values']['role']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(AuditEntry::query()->sole()->getAttributes()));
        $this->assertStringNotContainsString($admin->password, json_encode(AuditEntry::query()->sole()->getAttributes()));
    }
}
