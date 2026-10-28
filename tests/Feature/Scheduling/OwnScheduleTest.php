<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A doctor reads their own schedule, slots, and time off without knowing their doctor id (US4,
 * FR-018). The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class OwnScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const OWN = [
        'schedule' => ['/api/v1/doctors/me/schedule', '/api/v1/doctors/%d/schedule'],
        'slots' => ['/api/v1/doctors/me/slots?from=2026-11-01&to=2026-11-01', '/api/v1/doctors/%d/slots?from=2026-11-01&to=2026-11-01'],
        'time off' => ['/api/v1/doctors/me/time-off?from=2026-11-01&to=2026-11-30', '/api/v1/doctors/%d/time-off?from=2026-11-01&to=2026-11-30'],
    ];

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));

        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->weekdays()->create(['doctor_id' => $this->doctor->id]);
        DoctorTimeOff::factory()->withReason()->create(['doctor_id' => $this->doctor->id, 'start_date' => '2026-11-03', 'end_date' => '2026-11-03']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ownEndpoints(): array
    {
        return self::OWN;
    }

    #[DataProvider('ownEndpoints')]
    public function test_a_doctor_sees_what_staff_see_for_their_profile(string $own, string $byId): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());
        $expected = $this->getJson(sprintf($byId, $this->doctor->id))->assertOk()->json();

        Sanctum::actingAs($this->doctor->user);
        $this->getJson($own)->assertOk()->assertExactJson($expected);
    }

    public function test_the_slot_range_rules_apply(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->getJson('/api/v1/doctors/me/slots?from=2026-10-31&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ownPaths(): array
    {
        return array_map(fn (array $paths) => [$paths[0]], self::OWN);
    }

    #[DataProvider('ownPaths')]
    public function test_a_doctor_without_a_profile_is_not_found(string $own): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->getJson($own)->assertNotFound();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function otherRolesAndEndpoints(): array
    {
        $cases = [];

        foreach (['administrator', 'receptionist'] as $role) {
            foreach (self::OWN as $name => [$own]) {
                $cases["{$role} {$name}"] = [$role, $own];
            }
        }

        return $cases;
    }

    #[DataProvider('otherRolesAndEndpoints')]
    public function test_other_roles_are_forbidden(string $role, string $own): void
    {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson($own)->assertForbidden();
    }
}
