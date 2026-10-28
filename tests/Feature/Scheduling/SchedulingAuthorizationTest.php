<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Scheduling\Models\SchedulePeriod;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Every role against every scheduling endpoint: reads for all active staff, changes for
 * administrators only, /doctors/me/… for doctors only, and no access without a valid token
 * (SC-005, FR-019). The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class SchedulingAuthorizationTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    private Doctor $doctor;

    private DoctorTimeOff $timeOff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->weekdays()->create(['doctor_id' => $this->doctor->id]);
        $this->timeOff = DoctorTimeOff::factory()->create(['doctor_id' => $this->doctor->id, 'start_date' => '2026-11-05', 'end_date' => '2026-11-05']);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function changeEndpoints(): array
    {
        return [
            'set schedule' => ['PUT', '/api/v1/doctors/{doctor}/schedule', [
                'appointment_minutes' => 30, 'periods' => [['day' => 'monday', 'start' => '08:00', 'end' => '12:00']],
            ]],
            'clear schedule' => ['DELETE', '/api/v1/doctors/{doctor}/schedule', []],
            'record time off' => ['POST', '/api/v1/doctors/{doctor}/time-off', ['start_date' => '2026-11-10', 'end_date' => '2026-11-10']],
            'remove time off' => ['DELETE', '/api/v1/doctors/{doctor}/time-off/{timeOff}', []],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readEndpoints(): array
    {
        return [
            'schedule' => ['/api/v1/doctors/{doctor}/schedule'],
            'slots' => ['/api/v1/doctors/{doctor}/slots?from=2026-11-01&to=2026-11-07'],
            'time off' => ['/api/v1/doctors/{doctor}/time-off?from=2026-11-01&to=2026-11-30'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ownEndpoints(): array
    {
        return [
            'own schedule' => ['/api/v1/doctors/me/schedule'],
            'own slots' => ['/api/v1/doctors/me/slots?from=2026-11-01&to=2026-11-07'],
            'own time off' => ['/api/v1/doctors/me/time-off?from=2026-11-01&to=2026-11-30'],
        ];
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
     * @return array<string, array{Role, string}>
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
     * @return array<string, array{Role, string}>
     */
    public static function nonDoctorsAndOwnEndpoints(): array
    {
        $cases = [];

        foreach ([Role::Administrator, Role::Receptionist] as $role) {
            foreach (self::ownEndpoints() as $name => $endpoint) {
                $cases["{$role->value} {$name}"] = [$role, ...$endpoint];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function allEndpoints(): array
    {
        $endpoints = self::changeEndpoints();

        foreach ([...self::readEndpoints(), ...self::ownEndpoints()] as $name => [$uri]) {
            $endpoints[$name] = ['GET', $uri, []];
        }

        return $endpoints;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('changeEndpoints')]
    public function test_administrators_may_change(string $method, string $uri, array $body): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->json($method, $this->uri($uri), $body)->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('refusedRolesAndChanges')]
    public function test_receptionists_and_doctors_are_forbidden_to_change_and_nothing_changes(Role $role, string $method, string $uri, array $body): void
    {
        $before = $this->snapshot();
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->json($method, $this->uri($uri), $body)
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.forbidden'));

        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('rolesAndReads')]
    public function test_every_role_may_read(Role $role, string $uri): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson($this->uri($uri))->assertOk();
    }

    #[DataProvider('nonDoctorsAndOwnEndpoints')]
    public function test_only_doctors_may_use_the_own_endpoints(Role $role, string $uri): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson($uri)->assertForbidden();
    }

    #[DataProvider('ownEndpoints')]
    public function test_doctors_may_use_the_own_endpoints(string $uri): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->getJson($uri)->assertOk();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('allEndpoints')]
    public function test_requests_without_a_token_are_unauthenticated(string $method, string $uri, array $body): void
    {
        $this->json($method, $this->uri($uri), $body)->assertUnauthorized();
    }

    public function test_a_deactivated_administrator_is_unauthenticated(): void
    {
        $administrator = User::factory()->administrator()->create();
        $token = $administrator->createToken('test')->plainTextToken;
        $uri = $this->uri('/api/v1/doctors/{doctor}/schedule');

        $this->usingToken($token)->getJson($uri)->assertOk();

        $administrator->forceFill(['is_active' => false])->save();

        $this->usingToken($token)->getJson($uri)->assertUnauthorized();
    }

    public function test_receptionists_are_refused_before_validation(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->putJson($this->uri('/api/v1/doctors/{doctor}/schedule'), ['appointment_minutes' => 'x'])->assertForbidden();
    }

    private function uri(string $uri): string
    {
        return strtr($uri, ['{doctor}' => (string) $this->doctor->id, '{timeOff}' => (string) $this->timeOff->id]);
    }

    /**
     * Every scheduling row, to show a refused request changed nothing.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'schedules' => DoctorSchedule::query()->orderBy('id')->get()->toArray(),
            'periods' => SchedulePeriod::query()->orderBy('id')->get()->toArray(),
            'time_off' => DoctorTimeOff::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
