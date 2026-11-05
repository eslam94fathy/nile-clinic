<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditActorType;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Staff\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * Sign-ins (US3 scenarios 1–3, research R6). A failed one names the account targeted, never the password
 * tried, and never the text of an email that matches no account (FR-008).
 */
class AuditSignInTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    private const URI = '/api/v1/auth/sign-in';

    private const PLANTED_PASSWORD = 'planted-password-1';

    private const PLANTED_EMAIL = 'nobody-planted@clinic.test';

    private function allStoredText(): string
    {
        return json_encode(AuditEntry::query()->get()->map->getAttributes()->all());
    }

    public function test_a_wrong_password_names_the_account_and_never_the_password(): void
    {
        $user = User::factory()->doctor()->create();

        $this->postJson(self::URI, ['email' => $user->email, 'password' => self::PLANTED_PASSWORD])->assertUnprocessable();

        $this->assertOneEntry(AuditAction::AuthSignIn, [
            'outcome' => AuditOutcome::Refused,
            'actor_type' => AuditActorType::Staff,
            'actor_id' => $user->id,
            'actor_role' => 'doctor',
            'subject_type' => AuditSubject::StaffMember,
            'subject_id' => $user->id,
            'network_address' => '127.0.0.1',
            'details' => null,
        ]);
        $this->assertStringNotContainsString(self::PLANTED_PASSWORD, $this->allStoredText());
    }

    public function test_a_deactivated_account_is_refused_and_recorded_even_with_the_right_password(): void
    {
        $user = User::factory()->inactive()->create();

        $this->postJson(self::URI, ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertUnprocessable();

        $this->assertOneEntry(AuditAction::AuthSignIn, ['outcome' => AuditOutcome::Refused, 'actor_id' => $user->id]);
    }

    public function test_an_unknown_email_is_recorded_with_no_staff_member_and_not_its_text(): void
    {
        $this->postJson(self::URI, ['email' => self::PLANTED_EMAIL, 'password' => self::PLANTED_PASSWORD])->assertUnprocessable();

        $this->assertOneEntry(AuditAction::AuthSignIn, [
            'outcome' => AuditOutcome::Refused,
            'actor_type' => AuditActorType::Unknown,
            'actor_id' => null,
            'actor_role' => null,
            'subject_type' => null,
            'subject_id' => null,
            'network_address' => '127.0.0.1',
        ]);

        $this->assertStringNotContainsString('planted', strtolower($this->allStoredText()));
    }

    public function test_attempts_stopped_by_the_limit_add_no_entries(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->postJson(self::URI, ['email' => $user->email, 'password' => self::PLANTED_PASSWORD])->assertUnprocessable();
        }

        // Five attempts per email reach the password check (002 FR-007); the other three do not.
        $this->assertSame(5, AuditEntry::query()->where('action', AuditAction::AuthSignIn)->count());
    }

    public function test_a_request_that_fails_validation_adds_no_entry(): void
    {
        $this->postJson(self::URI, ['email' => 'someone@clinic.test'])->assertUnprocessable();

        $this->assertNoEntries(AuditAction::AuthSignIn);
    }

    public function test_when_the_entry_fails_a_refusal_keeps_its_response_and_the_failure_is_reported(): void
    {
        Exceptions::fake();
        $user = User::factory()->create();
        $this->failAuditWrites();

        $this->postJson(self::URI, ['email' => $user->email, 'password' => self::PLANTED_PASSWORD])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => __('auth.failed')]);

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_wrong_current_password_on_a_password_change_adds_no_entry(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'not-my-password-1',
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])->assertUnprocessable();

        $this->assertNoEntries(AuditAction::AuthPasswordChanged);
    }

    public function test_filtering_by_staff_member_finds_their_sign_ins_and_what_was_done_to_their_account(): void
    {
        $member = User::factory()->receptionist()->create();
        $other = User::factory()->receptionist()->create();
        $administrator = User::factory()->administrator()->create();

        $this->postJson(self::URI, ['email' => $member->email, 'password' => UserFactory::PASSWORD])->assertOk();
        $this->postJson(self::URI, ['email' => $member->email, 'password' => self::PLANTED_PASSWORD])->assertUnprocessable();
        $this->postJson(self::URI, ['email' => $other->email, 'password' => UserFactory::PASSWORD])->assertOk();

        Sanctum::actingAs($administrator);
        $this->patchJson("/api/v1/staff/{$member->id}", ['role' => 'doctor'])->assertOk();
        $this->postJson("/api/v1/staff/{$member->id}/deactivate")->assertOk();

        $response = $this->getJson("/api/v1/audit-entries?staff_id={$member->id}")->assertOk();

        $this->assertSame(
            ['staff.deactivated', 'staff.updated', 'auth.sign_in', 'auth.sign_in'],
            array_column($response->json('data'), 'action'),
        );
        $this->assertSame(['refused', 'succeeded'], array_column(array_slice($response->json('data'), 2), 'outcome'));

        // An administrator's change names both the administrator who acted and the member acted on.
        $this->assertSame($administrator->id, $response->json('data.0.actor.id'));
        $this->assertSame(['type' => 'staff_member', 'id' => $member->id], $response->json('data.0.subject'));
    }

    public function test_a_period_filter_narrows_a_staff_members_activity(): void
    {
        $member = User::factory()->create();
        $old = AuditEntry::factory()->create(['actor_id' => $member->id, 'created_at' => now()->subDays(10)]);
        $recent = AuditEntry::factory()->create(['actor_id' => $member->id, 'created_at' => now()->subDay()]);
        Sanctum::actingAs(User::factory()->administrator()->create());

        $from = now()->subDays(3)->toDateString();

        $ids = array_column($this->getJson("/api/v1/audit-entries?staff_id={$member->id}&from={$from}")->assertOk()->json('data'), 'id');

        $this->assertSame([$recent->id], $ids);
        $this->assertNotContains($old->id, $ids);
    }
}
