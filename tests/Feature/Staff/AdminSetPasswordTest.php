<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * An administrator sets a new password for a member and ends all of the member's access (FR-020).
 */
class AdminSetPasswordTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    public function test_new_password_works_and_every_earlier_token_is_revoked(): void
    {
        $admin = User::factory()->administrator()->create();
        $member = User::factory()->create();
        $first = $this->signIn($member);
        $second = $this->signIn($member);

        $this->usingToken(null);
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/staff/{$member->id}/password", [
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])->assertNoContent();

        $this->usingToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->usingToken($second)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->usingToken(null)->postJson('/api/v1/auth/sign-in', [
            'email' => $member->email,
            'password' => UserFactory::PASSWORD,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/sign-in', [
            'email' => $member->email,
            'password' => 'brand-new-pass-1',
        ])->assertOk();
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function invalidPasswords(): array
    {
        return [
            '11 characters' => [['password' => str_repeat('p', 11), 'password_confirmation' => str_repeat('p', 11)]],
            '73 characters' => [['password' => str_repeat('p', 73), 'password_confirmation' => str_repeat('p', 73)]],
            'unconfirmed' => [['password' => 'brand-new-pass-1', 'password_confirmation' => 'different-pass-1']],
        ];
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('invalidPasswords')]
    public function test_password_rules_apply(array $body): void
    {
        $member = User::factory()->create();
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->putJson("/api/v1/staff/{$member->id}/password", $body)
            ->assertJsonValidationErrorFor('password');
    }

    public function test_unknown_id_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->putJson('/api/v1/staff/999999/password', [
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])->assertNotFound();
    }
}
