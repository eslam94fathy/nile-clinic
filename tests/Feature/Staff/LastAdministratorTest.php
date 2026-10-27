<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The clinic is never left without an active administrator (US3 scenario 7, FR-021).
 */
class LastAdministratorTest extends TestCase
{
    use RefreshDatabase;

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
    public function test_only_active_administrator_cannot_deactivate_themselves(string $locale): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/staff/{$admin->id}/deactivate", [], ['Accept-Language' => $locale])
            ->assertUnprocessable()
            ->assertJsonPath('message', $this->message($locale));

        $this->assertTrue($admin->fresh()->is_active);
    }

    #[DataProvider('locales')]
    public function test_only_active_administrator_cannot_change_their_own_role(string $locale): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/staff/{$admin->id}", ['role' => 'doctor'], ['Accept-Language' => $locale])
            ->assertUnprocessable()
            ->assertJsonPath('message', $this->message($locale));

        $this->assertSame(Role::Administrator, $admin->fresh()->role);
    }

    public function test_an_inactive_administrator_does_not_count(): void
    {
        $admin = User::factory()->administrator()->create();
        User::factory()->administrator()->inactive()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/staff/{$admin->id}/deactivate")->assertUnprocessable();
    }

    public function test_with_a_second_active_administrator_deactivation_is_allowed(): void
    {
        $admin = User::factory()->administrator()->create();
        $other = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/staff/{$other->id}/deactivate")->assertOk();
    }

    public function test_with_a_second_active_administrator_a_role_change_is_allowed(): void
    {
        $admin = User::factory()->administrator()->create();
        User::factory()->administrator()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/staff/{$admin->id}", ['role' => 'doctor'])
            ->assertOk()
            ->assertJsonPath('data.role', 'doctor');
    }

    public function test_the_rule_does_not_apply_to_other_roles(): void
    {
        $admin = User::factory()->administrator()->create();
        $receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/staff/{$receptionist->id}/deactivate")->assertOk();
        $this->patchJson("/api/v1/staff/{$receptionist->id}", ['role' => 'doctor'])->assertOk();
    }

    private function message(string $locale): string
    {
        return (require app_path("Modules/Staff/lang/{$locale}/messages.php"))['last_administrator'];
    }
}
