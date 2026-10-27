<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Administrators create, list, view, and edit staff accounts (US3, FR-016–FR-018).
 */
class StaffManagementTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->administrator()->create(['name' => 'Admin Zed']);
        Sanctum::actingAs($this->admin);
    }

    public function test_administrator_creates_an_active_account_that_can_sign_in(): void
    {
        $this->postJson('/api/v1/staff', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.email', 'reception@clinic.test')
            ->assertJsonPath('data.role', 'receptionist')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $this->postJson('/api/v1/auth/sign-in', [
            'email' => 'reception@clinic.test',
            'password' => 'reception-pass-1',
        ])->assertOk();
    }

    public function test_email_is_stored_trimmed_and_lower_cased(): void
    {
        $this->postJson('/api/v1/staff', $this->payload(['email' => ' Reception@Clinic.test ']))
            ->assertCreated()
            ->assertJsonPath('data.email', 'reception@clinic.test');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function takenEmails(): array
    {
        return [
            'active owner' => ['taken@clinic.test'],
            'deactivated owner' => ['gone@clinic.test'],
            'different case' => ['Taken@Clinic.test'],
        ];
    }

    #[DataProvider('takenEmails')]
    public function test_email_must_be_unique_among_all_accounts(string $email): void
    {
        User::factory()->create(['email' => 'taken@clinic.test']);
        User::factory()->inactive()->create(['email' => 'gone@clinic.test']);

        $this->postJson('/api/v1/staff', $this->payload(['email' => $email]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('email');
    }

    public function test_email_must_be_printable_ascii(): void
    {
        $this->postJson('/api/v1/staff', $this->payload(['email' => 'ｒeception@clinic.test']))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('email');
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function passwordLengths(): array
    {
        return [
            '11 characters' => [11, false],
            '12 characters' => [12, true],
            '72 characters' => [72, true],
            '73 characters' => [73, false],
        ];
    }

    #[DataProvider('passwordLengths')]
    public function test_password_must_be_12_to_72_characters(int $length, bool $valid): void
    {
        $password = str_repeat('p', $length);
        $response = $this->postJson('/api/v1/staff', $this->payload([
            'password' => $password,
            'password_confirmation' => $password,
        ]));

        $valid ? $response->assertCreated() : $response->assertJsonValidationErrorFor('password');
    }

    public function test_password_must_be_confirmed(): void
    {
        $this->postJson('/api/v1/staff', $this->payload(['password_confirmation' => 'something-else-1']))
            ->assertJsonValidationErrorFor('password');
    }

    public function test_role_must_be_one_of_the_three_roles(): void
    {
        $this->postJson('/api/v1/staff', $this->payload(['role' => 'nurse']))
            ->assertJsonValidationErrorFor('role');
    }

    public function test_list_is_paginated_ordered_and_hides_passwords(): void
    {
        $adamTwo = User::factory()->create(['name' => 'Adam']);
        $adamOne = User::factory()->create(['name' => 'Adam']);
        User::factory()->create(['name' => 'Bea']);

        $response = $this->getJson('/api/v1/staff');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'is_active']], 'links', 'meta'])
            ->assertJsonPath('meta.per_page', 15);

        $this->assertSame(['Adam', 'Adam', 'Admin Zed', 'Bea'], array_column($response->json('data'), 'name'));
        $this->assertSame([$adamTwo->id, $adamOne->id], array_slice(array_column($response->json('data'), 'id'), 0, 2));
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_list_filters_by_role_and_active_status(): void
    {
        User::factory()->doctor()->create();
        User::factory()->doctor()->inactive()->create();
        User::factory()->receptionist()->create();

        $this->assertCount(2, $this->getJson('/api/v1/staff?role=doctor')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/staff?role=doctor&is_active=true')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/staff?is_active=false')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/staff?is_active=0')->json('data'));
    }

    public function test_list_validates_its_filters(): void
    {
        $this->getJson('/api/v1/staff?per_page=101')->assertJsonValidationErrorFor('per_page');
        $this->getJson('/api/v1/staff?role=nurse')->assertJsonValidationErrorFor('role');
        $this->getJson('/api/v1/staff?per_page=2')->assertOk()->assertJsonPath('meta.per_page', 2);
    }

    public function test_show_returns_the_account(): void
    {
        $member = User::factory()->doctor()->create();

        $this->getJson("/api/v1/staff/{$member->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.role', 'doctor');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return [
            'en' => ['en'],
            'ar' => ['ar'],
        ];
    }

    #[DataProvider('locales')]
    public function test_unknown_id_is_not_found(string $locale): void
    {
        $this->getJson('/api/v1/staff/999999', ['Accept-Language' => $locale])
            ->assertNotFound()
            ->assertJsonPath('message', (require app_path("Modules/Staff/lang/{$locale}/messages.php"))['not_found']);
    }

    public function test_update_changes_name_email_and_role(): void
    {
        $member = User::factory()->receptionist()->create();

        $this->patchJson("/api/v1/staff/{$member->id}", [
            'name' => 'Renamed Member',
            'email' => 'Renamed@Clinic.test',
            'role' => 'doctor',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Member')
            ->assertJsonPath('data.email', 'renamed@clinic.test')
            ->assertJsonPath('data.role', 'doctor');
    }

    public function test_update_keeps_own_email_but_rejects_another_accounts(): void
    {
        $member = User::factory()->create(['email' => 'member@clinic.test']);
        User::factory()->create(['email' => 'other@clinic.test']);

        $this->patchJson("/api/v1/staff/{$member->id}", ['email' => 'member@clinic.test'])->assertOk();
        $this->patchJson("/api/v1/staff/{$member->id}", ['email' => 'other@clinic.test'])
            ->assertJsonValidationErrorFor('email');
    }

    public function test_update_needs_at_least_one_field(): void
    {
        $member = User::factory()->create();

        $this->patchJson("/api/v1/staff/{$member->id}", [])->assertUnprocessable();
    }

    public function test_role_change_applies_on_the_members_next_request(): void
    {
        $member = User::factory()->receptionist()->create();
        $token = $this->signIn($member);

        $this->usingToken($token)->getJson('/api/v1/staff')->assertForbidden();

        $this->usingToken(null);
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/staff/{$member->id}", ['role' => 'administrator'])->assertOk();

        $this->usingToken($token)->getJson('/api/v1/staff')->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Reception',
            'email' => 'reception@clinic.test',
            'role' => 'receptionist',
            'password' => 'reception-pass-1',
            'password_confirmation' => 'reception-pass-1',
        ], $overrides);
    }
}
