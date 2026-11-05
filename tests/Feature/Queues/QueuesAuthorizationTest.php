<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Services\QueueService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Every role against every queue endpoint: the six reception endpoints for administrators and
 * receptionists, /doctors/me/queue… for doctors, and no access without a valid token (SC-005,
 * research R7). The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class QueuesAuthorizationTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    private Doctor $doctor;

    private Patient $patient;

    private Appointment $appointment;

    private QueueEntry $toLeave;

    private QueueEntry $toUndo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));

        $this->doctor = Doctor::factory()->create();
        $this->patient = Patient::factory()->create();
        $this->appointment = $this->appointmentAt('09:00');

        // Through the service, so that requests in the tests stay unauthenticated by default.
        $queue = app(QueueService::class);
        $receptionist = User::factory()->receptionist()->create();
        $this->toLeave = $queue->checkIn($receptionist, $this->appointmentAt('09:20')->id);
        $this->toUndo = $queue->checkIn($receptionist, $this->appointmentAt('09:40')->id);
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function receptionEndpoints(): array
    {
        return [
            'check in' => ['POST', '/api/v1/appointments/{appointment}/check-in', []],
            'walk-in' => ['POST', '/api/v1/queue/walk-ins', ['patient_id' => '{patient}', 'doctor_id' => '{doctor}']],
            'clinic list' => ['GET', '/api/v1/queue', []],
            'doctor list' => ['GET', '/api/v1/doctors/{doctor}/queue', []],
            'leave' => ['POST', '/api/v1/queue/{toLeave}/leave', []],
            'undo' => ['DELETE', '/api/v1/queue/{toUndo}', []],
        ];
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function ownEndpoints(): array
    {
        return [
            'own list' => ['GET', '/api/v1/doctors/me/queue', []],
            'call next' => ['POST', '/api/v1/doctors/me/queue/call-next', []],
            'finish' => ['POST', '/api/v1/doctors/me/queue/finish', []],
        ];
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function allEndpoints(): array
    {
        return [...self::receptionEndpoints(), ...self::ownEndpoints()];
    }

    /**
     * @return array<string, array{Role, string, string, array<string, string>}>
     */
    public static function staffAndReceptionEndpoints(): array
    {
        return self::cross(self::receptionEndpoints());
    }

    /**
     * @return array<string, array{Role, string, string, array<string, string>}>
     */
    public static function staffAndOwnEndpoints(): array
    {
        return self::cross(self::ownEndpoints());
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('staffAndReceptionEndpoints')]
    public function test_administrators_and_receptionists_may_use_the_reception_endpoints(Role $role, string $method, string $uri, array $body): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->json($method, $this->uri($uri), $this->body($body))->assertSuccessful();
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('receptionEndpoints')]
    public function test_doctors_are_forbidden_on_the_reception_endpoints_and_nothing_changes(string $method, string $uri, array $body): void
    {
        $before = $this->snapshot();
        Sanctum::actingAs($this->doctor->user);

        $this->json($method, $this->uri($uri), $this->body($body))
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.forbidden'));

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('staffAndOwnEndpoints')]
    public function test_administrators_and_receptionists_are_forbidden_on_the_own_endpoints(Role $role, string $method, string $uri, array $body): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->json($method, $uri, $body)
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.forbidden'));
    }

    public function test_doctors_may_use_the_own_endpoints(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->getJson('/api/v1/doctors/me/queue')->assertOk();
        $this->postJson('/api/v1/doctors/me/queue/call-next')->assertOk();
        $this->postJson('/api/v1/doctors/me/queue/finish')->assertOk();
    }

    /**
     * @param  array<string, string>  $body
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

        $this->usingToken($token)->getJson('/api/v1/queue')->assertOk();

        $receptionist->forceFill(['is_active' => false])->save();

        $this->usingToken($token)->getJson('/api/v1/queue')->assertUnauthorized();
    }

    public function test_doctors_are_refused_before_validation(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->postJson('/api/v1/queue/walk-ins', ['patient_id' => 'someone'])->assertForbidden();
    }

    /**
     * @param  array<string, array{string, string, array<string, string>}>  $endpoints
     * @return array<string, array{Role, string, string, array<string, string>}>
     */
    private static function cross(array $endpoints): array
    {
        $cases = [];

        foreach ([Role::Administrator, Role::Receptionist] as $role) {
            foreach ($endpoints as $name => $endpoint) {
                $cases["{$role->value} {$name}"] = [$role, ...$endpoint];
            }
        }

        return $cases;
    }

    private function appointmentAt(string $start): Appointment
    {
        return Appointment::factory()->at('2026-11-01', $start)->create(['doctor_id' => $this->doctor->id]);
    }

    private function uri(string $uri): string
    {
        return strtr($uri, [
            '{appointment}' => (string) $this->appointment->id,
            '{doctor}' => (string) $this->doctor->id,
            '{toLeave}' => (string) $this->toLeave->id,
            '{toUndo}' => (string) $this->toUndo->id,
        ]);
    }

    /**
     * @param  array<string, string>  $body
     * @return array<string, mixed>
     */
    private function body(array $body): array
    {
        return array_map(fn (string $value) => match ($value) {
            '{patient}' => $this->patient->id,
            '{doctor}' => $this->doctor->id,
            default => $value,
        }, $body);
    }

    /**
     * Every queue entry and appointment row, to show a refused request changed nothing.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        return [
            QueueEntry::query()->orderBy('id')->get()->toArray(),
            Appointment::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
