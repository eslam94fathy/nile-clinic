<?php

namespace Tests\Feature\Appointments;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Every role against every appointments endpoint: /appointments… for administrators and
 * receptionists, /doctors/me/appointments… for doctors, and no access without a valid token
 * (SC-006, research R8). The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class AppointmentsAuthorizationTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    private Doctor $doctor;

    private Patient $patient;

    private Appointment $upcoming;

    private Appointment $started;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()
            ->withPeriod('sunday', '09:00', '10:00')
            ->withPeriod('tuesday', '10:00', '11:00')
            ->create(['doctor_id' => $this->doctor->id]);
        $this->patient = Patient::factory()->create();
        $this->upcoming = Appointment::factory()->at('2026-11-03', '10:00')->create(['doctor_id' => $this->doctor->id]);
        $this->started = Appointment::factory()->at('2026-10-29', '10:00')->create(['doctor_id' => $this->doctor->id]);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function staffEndpoints(): array
    {
        return [
            'list' => ['GET', '/api/v1/appointments?from=2026-11-01&to=2026-11-07', []],
            'book' => ['POST', '/api/v1/appointments', ['patient_id' => '{patient}', 'doctor_id' => '{doctor}', 'date' => '2026-11-01', 'start' => '09:20']],
            'show' => ['GET', '/api/v1/appointments/{upcoming}', []],
            'reschedule' => ['POST', '/api/v1/appointments/{upcoming}/reschedule', ['date' => '2026-11-01', 'start' => '09:40']],
            'cancel' => ['POST', '/api/v1/appointments/{upcoming}/cancel', ['reason' => 'Synthetic reason']],
            'no-show' => ['POST', '/api/v1/appointments/{started}/no-show', []],
        ];
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function ownEndpoints(): array
    {
        return [
            'own list' => ['GET', '/api/v1/doctors/me/appointments?from=2026-11-01&to=2026-11-07', []],
            'own show' => ['GET', '/api/v1/doctors/me/appointments/{upcoming}', []],
        ];
    }

    /**
     * @return array<string, array{Role, string, string, array<string, mixed>}>
     */
    public static function staffRolesAndEndpoints(): array
    {
        return self::cross([Role::Administrator, Role::Receptionist], self::staffEndpoints());
    }

    /**
     * @return array<string, array{Role, string, string, array<string, mixed>}>
     */
    public static function staffRolesAndOwnEndpoints(): array
    {
        return self::cross([Role::Administrator, Role::Receptionist], self::ownEndpoints());
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function allEndpoints(): array
    {
        return [...self::staffEndpoints(), ...self::ownEndpoints()];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('staffRolesAndEndpoints')]
    public function test_administrators_and_receptionists_may_use_the_staff_endpoints(Role $role, string $method, string $uri, array $body): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->json($method, $this->uri($uri), $this->body($body))->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('staffEndpoints')]
    public function test_doctors_are_forbidden_on_the_staff_endpoints_and_nothing_changes(string $method, string $uri, array $body): void
    {
        $before = $this->snapshot();
        Sanctum::actingAs($this->doctor->user);

        $this->json($method, $this->uri($uri), $this->body($body))
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.forbidden'));

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('staffRolesAndOwnEndpoints')]
    public function test_only_doctors_may_use_the_own_endpoints(Role $role, string $method, string $uri, array $body): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->json($method, $this->uri($uri), $body)->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('ownEndpoints')]
    public function test_doctors_may_use_the_own_endpoints(string $method, string $uri, array $body): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->json($method, $this->uri($uri), $body)->assertOk();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('allEndpoints')]
    public function test_requests_without_a_token_are_unauthenticated(string $method, string $uri, array $body): void
    {
        $this->json($method, $this->uri($uri), $this->body($body))->assertUnauthorized();
    }

    public function test_a_deactivated_receptionist_is_unauthenticated(): void
    {
        $receptionist = User::factory()->receptionist()->create();
        $token = $receptionist->createToken('test')->plainTextToken;
        $uri = "/api/v1/appointments/{$this->upcoming->id}";

        $this->usingToken($token)->getJson($uri)->assertOk();

        $receptionist->forceFill(['is_active' => false])->save();

        $this->usingToken($token)->getJson($uri)->assertUnauthorized();
    }

    public function test_doctors_are_refused_before_validation(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->postJson('/api/v1/appointments', ['start' => 'soon'])->assertForbidden();
    }

    /**
     * @param  list<Role>  $roles
     * @param  array<string, array{string, string, array<string, mixed>}>  $endpoints
     * @return array<string, array{Role, string, string, array<string, mixed>}>
     */
    private static function cross(array $roles, array $endpoints): array
    {
        $cases = [];

        foreach ($roles as $role) {
            foreach ($endpoints as $name => $endpoint) {
                $cases["{$role->value} {$name}"] = [$role, ...$endpoint];
            }
        }

        return $cases;
    }

    private function uri(string $uri): string
    {
        return strtr($uri, ['{upcoming}' => (string) $this->upcoming->id, '{started}' => (string) $this->started->id]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function body(array $body): array
    {
        return array_map(fn (mixed $value) => match ($value) {
            '{patient}' => $this->patient->id,
            '{doctor}' => $this->doctor->id,
            default => $value,
        }, $body);
    }

    /**
     * Every appointment row, to show a refused request changed nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        return Appointment::query()->orderBy('id')->get()->toArray();
    }
}
