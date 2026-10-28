<?php

namespace Tests\Feature\Patients;

use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Every patient endpoint allows administrators and receptionists, refuses doctors without
 * changing anything, and refuses requests without valid access (SC-005, FR-014, FR-015).
 */
class PatientAuthorizationTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['GET', '/api/v1/patients', []],
            'register' => ['POST', '/api/v1/patients', [
                'full_name' => 'New Patient',
                'date_of_birth' => '1990-01-01',
                'sex' => 'female',
                'phone' => '01000000005',
            ]],
            'show' => ['GET', '/api/v1/patients/{id}', []],
            'update' => ['PATCH', '/api/v1/patients/{id}', ['full_name' => 'Changed Name']],
        ];
    }

    /**
     * @return array<string, array{Role, string, string, array<string, mixed>}>
     */
    public static function allowedRolesAndEndpoints(): array
    {
        $cases = [];

        foreach ([Role::Administrator, Role::Receptionist] as $role) {
            foreach (self::endpoints() as $name => $endpoint) {
                $cases["{$role->value} {$name}"] = [$role, ...$endpoint];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('endpoints')]
    public function test_doctors_are_forbidden_and_nothing_changes(string $method, string $uri, array $body): void
    {
        $patient = Patient::factory()->create(['full_name' => 'Original Name']);
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->json($method, str_replace('{id}', (string) $patient->id, $uri), $body)
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.forbidden'));

        $this->assertDatabaseCount('patients', 1);
        $this->assertSame('Original Name', $patient->fresh()->full_name);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('allowedRolesAndEndpoints')]
    public function test_administrators_and_receptionists_are_allowed(Role $role, string $method, string $uri, array $body): void
    {
        $patient = Patient::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->json($method, str_replace('{id}', (string) $patient->id, $uri), $body)->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('endpoints')]
    public function test_requests_without_a_token_are_unauthenticated(string $method, string $uri, array $body): void
    {
        $patient = Patient::factory()->create();

        $this->json($method, str_replace('{id}', (string) $patient->id, $uri), $body)->assertUnauthorized();
    }

    public function test_a_deactivated_receptionist_is_unauthenticated(): void
    {
        $receptionist = User::factory()->receptionist()->create();
        $token = $receptionist->createToken('test')->plainTextToken;

        $this->usingToken($token)->getJson('/api/v1/patients')->assertOk();

        $receptionist->forceFill(['is_active' => false])->save();

        $this->usingToken($token)->getJson('/api/v1/patients')->assertUnauthorized();
    }

    public function test_doctors_are_refused_before_validation(): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->postJson('/api/v1/patients', ['phone' => 'not a phone'])->assertForbidden();
    }
}
