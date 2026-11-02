<?php

namespace Tests\Feature\Appointments;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A doctor sees only their own appointments, with a short patient summary, and still has no
 * access to patient records (US5, FR-015–FR-017, spec Q1, research R8). The clock is fixed at
 * Sunday 2026-11-01 08:00, clinic time.
 */
class OwnAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY_KEYS = ['id', 'date', 'start', 'end', 'status', 'outside_availability', 'patient'];

    private Doctor $doctor;

    private Patient $patient;

    private Appointment $tuesday;

    private Appointment $sundayCancelled;

    private Appointment $others;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));

        $this->doctor = Doctor::factory()->create();
        $this->patient = Patient::factory()->withNationalId()->create(['address' => 'Synthetic address']);

        $this->tuesday = Appointment::factory()->at('2026-11-03', '10:00')->create([
            'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'note' => 'Synthetic note',
        ]);
        $this->sundayCancelled = Appointment::factory()->at('2026-11-01', '09:20')->cancelled()->create(['doctor_id' => $this->doctor->id]);
        $this->others = Appointment::factory()->at('2026-11-01', '09:00')->create(['patient_id' => $this->patient->id]);

        Sanctum::actingAs($this->doctor->user);
    }

    public function test_a_doctor_lists_only_their_own_appointments_in_order(): void
    {
        $response = $this->getJson('/api/v1/doctors/me/appointments?from=2026-11-01&to=2026-11-03')->assertOk();

        $this->assertSame([$this->sundayCancelled->id, $this->tuesday->id], array_column($response->json('data'), 'id'));
        foreach ($response->json('data') as $item) {
            $this->assertSame(self::SUMMARY_KEYS, array_keys($item));
            $this->assertSame(['id', 'full_name', 'sex', 'date_of_birth'], array_keys($item['patient']));
        }
        $response->assertJsonPath('data.1.patient', [
            'id' => $this->patient->id,
            'full_name' => $this->patient->full_name,
            'sex' => $this->patient->sex->value,
            'date_of_birth' => $this->patient->date_of_birth->toDateString(),
        ]);

        $json = $response->getContent();
        foreach (['note', 'phone', 'national_id', 'address', 'emergency_contact', 'Synthetic note', 'Synthetic address'] as $text) {
            $this->assertStringNotContainsString($text, $json);
        }
    }

    public function test_the_own_list_can_be_filtered_by_status(): void
    {
        $this->getJson('/api/v1/doctors/me/appointments?from=2026-11-01&to=2026-11-03&status=scheduled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->tuesday->id);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidRanges(): array
    {
        return [
            'missing dates' => ['', 'from'],
            'to before from' => ['from=2026-11-05&to=2026-11-04', 'to'],
            'more than 92 days' => ['from=2026-11-01&to=2027-02-01', 'to'],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_the_range_rules_apply(string $query, string $field): void
    {
        $this->getJson("/api/v1/doctors/me/appointments?{$query}")->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    public function test_a_doctor_opens_one_of_their_own_appointments(): void
    {
        $response = $this->getJson("/api/v1/doctors/me/appointments/{$this->tuesday->id}")->assertOk();

        $this->assertSame([...self::SUMMARY_KEYS, 'note', 'booked_at'], array_keys($response->json('data')));
        $response->assertJsonPath('data.note', 'Synthetic note')
            ->assertJsonPath('data.booked_at', $this->tuesday->created_at->toISOString());
    }

    public function test_another_doctors_appointment_is_not_found(): void
    {
        $this->getJson("/api/v1/doctors/me/appointments/{$this->others->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => __('appointments::messages.not_found')]);
        $this->getJson('/api/v1/doctors/me/appointments/999999')->assertNotFound();
    }

    public function test_a_doctor_without_a_profile_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->getJson('/api/v1/doctors/me/appointments?from=2026-11-01&to=2026-11-03')->assertNotFound();
        $this->getJson("/api/v1/doctors/me/appointments/{$this->tuesday->id}")->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staffRoles(): array
    {
        return ['administrator' => ['administrator'], 'receptionist' => ['receptionist']];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_cannot_use_the_own_endpoints(string $role): void
    {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/v1/doctors/me/appointments?from=2026-11-01&to=2026-11-03')->assertForbidden();
        $this->getJson("/api/v1/doctors/me/appointments/{$this->tuesday->id}")->assertForbidden();
    }

    public function test_a_doctor_still_cannot_use_the_staff_endpoints_or_patient_records(): void
    {
        $this->getJson('/api/v1/appointments?from=2026-11-01&to=2026-11-03')->assertForbidden();
        $this->getJson("/api/v1/appointments/{$this->tuesday->id}")->assertForbidden();
        $this->getJson("/api/v1/patients/{$this->patient->id}")->assertForbidden();
    }
}
