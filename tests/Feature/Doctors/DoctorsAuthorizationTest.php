<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Every role against every doctors and specialties endpoint: reads for all active staff, changes
 * for administrators only, /doctors/me for doctors only, and no access without a valid token
 * (SC-004, FR-003, FR-010, FR-017, FR-018).
 */
class DoctorsAuthorizationTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    /**
     * Change endpoints with valid bodies. {specialty}, {doctor}, {account}, and {new_specialty}
     * are replaced with the ids made in prepare().
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function changeEndpoints(): array
    {
        return [
            'add specialty' => ['POST', '/api/v1/specialties', ['name_en' => 'Dermatology', 'name_ar' => 'الجلدية']],
            'rename specialty' => ['PATCH', '/api/v1/specialties/{specialty}', ['name_en' => 'Renamed']],
            'deactivate specialty' => ['POST', '/api/v1/specialties/{specialty}/deactivate', []],
            'reactivate specialty' => ['POST', '/api/v1/specialties/{specialty}/reactivate', []],
            'create doctor' => ['POST', '/api/v1/doctors', ['user_id' => '{account}', 'title' => 'Consultant', 'specialty_ids' => ['{new_specialty}']]],
            'update doctor' => ['PATCH', '/api/v1/doctors/{doctor}', ['title' => 'Changed']],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function readEndpoints(): array
    {
        return [
            'list specialties' => ['GET', '/api/v1/specialties'],
            'list doctors' => ['GET', '/api/v1/doctors'],
            'show doctor' => ['GET', '/api/v1/doctors/{doctor}'],
        ];
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function allEndpoints(): array
    {
        $endpoints = self::changeEndpoints();

        foreach ([...self::readEndpoints(), 'own profile' => ['GET', '/api/v1/doctors/me']] as $name => [$method, $uri]) {
            $endpoints[$name] = [$method, $uri, []];
        }

        return $endpoints;
    }

    /**
     * @return array<string, array{Role, string, string, array<string, mixed>}>
     */
    public static function refusedRolesAndChanges(): array
    {
        $cases = [];

        foreach ([Role::Receptionist, Role::Doctor] as $role) {
            foreach (self::changeEndpoints() as $name => $endpoint) {
                $cases["{$role->value} {$name}"] = [$role, ...$endpoint];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{Role, string, string}>
     */
    public static function rolesAndReads(): array
    {
        $cases = [];

        foreach (Role::cases() as $role) {
            foreach (self::readEndpoints() as $name => $endpoint) {
                $cases["{$role->value} {$name}"] = [$role, ...$endpoint];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('changeEndpoints')]
    public function test_administrators_may_change(string $method, string $uri, array $body): void
    {
        [$uri, $body] = $this->prepare($uri, $body);
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->json($method, $uri, $body)->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('refusedRolesAndChanges')]
    public function test_receptionists_and_doctors_are_forbidden_to_change_and_nothing_changes(Role $role, string $method, string $uri, array $body): void
    {
        [$uri, $body] = $this->prepare($uri, $body);
        $before = $this->snapshot();
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->json($method, $uri, $body)
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.forbidden'));

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_doctor_cannot_update_their_own_profile(): void
    {
        $doctor = Doctor::factory()->withSpecialties()->create(['title' => 'Specialist']);
        Sanctum::actingAs($doctor->user);

        $this->patchJson("/api/v1/doctors/{$doctor->id}", ['title' => 'Consultant'])->assertForbidden();

        $this->assertSame('Specialist', $doctor->fresh()->title);
    }

    #[DataProvider('rolesAndReads')]
    public function test_every_role_may_read(Role $role, string $method, string $uri): void
    {
        [$uri] = $this->prepare($uri, []);
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->json($method, $uri)->assertOk();
    }

    public function test_only_doctors_may_fetch_their_own_profile(): void
    {
        $doctor = Doctor::factory()->withSpecialties()->create();

        foreach ([Role::Administrator, Role::Receptionist] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/doctors/me')->assertForbidden();
        }

        Sanctum::actingAs($doctor->user);
        $this->getJson('/api/v1/doctors/me')->assertOk();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('allEndpoints')]
    public function test_requests_without_a_token_are_unauthenticated(string $method, string $uri, array $body): void
    {
        [$uri, $body] = $this->prepare($uri, $body);

        $this->json($method, $uri, $body)->assertUnauthorized();
    }

    public function test_a_deactivated_administrator_is_unauthenticated(): void
    {
        $administrator = User::factory()->administrator()->create();
        $token = $administrator->createToken('test')->plainTextToken;

        $this->usingToken($token)->getJson('/api/v1/doctors')->assertOk();

        $administrator->forceFill(['is_active' => false])->save();

        $this->usingToken($token)->getJson('/api/v1/doctors')->assertUnauthorized();
    }

    public function test_receptionists_are_refused_before_validation(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->postJson('/api/v1/specialties', ['name_en' => ''])->assertForbidden();
    }

    /**
     * Make one specialty, one doctor, a free doctor-role account, and a spare specialty, and put
     * their ids into the uri and body.
     *
     * @param  array<string, mixed>  $body
     * @return array{string, array<string, mixed>}
     */
    private function prepare(string $uri, array $body): array
    {
        $ids = [
            '{specialty}' => Specialty::factory()->create()->id,
            '{doctor}' => Doctor::factory()->withSpecialties()->create()->id,
            '{account}' => User::factory()->doctor()->create()->id,
            '{new_specialty}' => Specialty::factory()->create()->id,
        ];

        array_walk_recursive($body, function (mixed &$value) use ($ids) {
            if (is_string($value) && isset($ids[$value])) {
                $value = $ids[$value];
            }
        });

        return [strtr($uri, array_map('strval', $ids)), $body];
    }

    /**
     * Every row of the three tables, to show a refused request changed nothing.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'specialties' => Specialty::query()->orderBy('id')->get()->toArray(),
            'doctors' => Doctor::query()->orderBy('id')->get()->toArray(),
            'assignments' => Doctor::query()->with('specialties:id')->orderBy('id')->get()
                ->map(fn (Doctor $d) => $d->specialties->modelKeys())->all(),
        ];
    }
}
