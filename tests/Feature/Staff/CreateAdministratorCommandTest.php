<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * staff:create-admin creates the first administrator; no credentials ship with the system (US4, FR-025).
 */
class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'first-admin-pass-1';

    public function test_creates_an_active_administrator_who_can_sign_in(): void
    {
        $this->artisan('staff:create-admin', ['--name' => 'Test Admin', '--email' => 'Admin@Clinic.test'])
            ->expectsQuestion(__('staff::messages.prompt_password'), self::PASSWORD)
            ->expectsQuestion(__('staff::messages.prompt_password_confirmation'), self::PASSWORD)
            ->expectsOutputToContain(__('staff::messages.administrator_created', ['email' => 'admin@clinic.test']))
            ->assertSuccessful();

        $admin = User::query()->sole();
        $this->assertSame('admin@clinic.test', $admin->email);
        $this->assertSame('Test Admin', $admin->name);
        $this->assertSame(Role::Administrator, $admin->role);
        $this->assertTrue($admin->is_active);

        $this->postJson('/api/v1/auth/sign-in', [
            'email' => 'admin@clinic.test',
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    public function test_asks_for_a_missing_name_and_email(): void
    {
        $this->artisan('staff:create-admin')
            ->expectsQuestion(__('staff::messages.prompt_name'), 'Asked Admin')
            ->expectsQuestion(__('staff::messages.prompt_email'), 'asked@clinic.test')
            ->expectsQuestion(__('staff::messages.prompt_password'), self::PASSWORD)
            ->expectsQuestion(__('staff::messages.prompt_password_confirmation'), self::PASSWORD)
            ->assertSuccessful();

        $this->assertSame('asked@clinic.test', User::query()->sole()->email);
    }

    public function test_duplicate_email_fails_and_creates_nothing(): void
    {
        User::factory()->create(['email' => 'admin@clinic.test']);

        $this->artisan('staff:create-admin', ['--name' => 'Test Admin', '--email' => 'admin@clinic.test'])
            ->expectsQuestion(__('staff::messages.prompt_password'), self::PASSWORD)
            ->expectsQuestion(__('staff::messages.prompt_password_confirmation'), self::PASSWORD)
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidPasswords(): array
    {
        return [
            '11 characters' => [str_repeat('p', 11), str_repeat('p', 11)],
            'mismatched confirmation' => [self::PASSWORD, 'different-pass-1'],
        ];
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_fails_and_creates_nothing(string $password, string $confirmation): void
    {
        $this->artisan('staff:create-admin', ['--name' => 'Test Admin', '--email' => 'admin@clinic.test'])
            ->expectsQuestion(__('staff::messages.prompt_password'), $password)
            ->expectsQuestion(__('staff::messages.prompt_password_confirmation'), $confirmation)
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_password_cannot_be_passed_as_an_option(): void
    {
        $this->assertArrayNotHasKey('password', $this->app[Kernel::class]->all()['staff:create-admin']->getDefinition()->getOptions());
    }
}
