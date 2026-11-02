<?php

namespace Tests\Feature\Appointments;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staff find appointments by date range and filters, and open one (US2, FR-008–FR-010). The clock
 * is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class ListAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY_KEYS = ['id', 'date', 'start', 'end', 'status', 'outside_availability', 'doctor', 'patient'];

    private Doctor $doctorA;

    private Doctor $doctorB;

    private Patient $patient1;

    private Patient $patient2;

    /**
     * @var array<string, Appointment>
     */
    private array $appointments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->doctorA = Doctor::factory()->create();
        $this->doctorB = Doctor::factory()->create();
        $this->patient1 = Patient::factory()->withNationalId()->create(['address' => 'Synthetic address']);
        $this->patient2 = Patient::factory()->create();

        $this->appointments = [
            'b_sun_0940' => $this->appointment($this->doctorB, $this->patient2, '2026-11-01', '09:40', ['note' => 'Synthetic note']),
            'a_sun_0920' => $this->appointment($this->doctorA, $this->patient1, '2026-11-01', '09:20'),
            'a_tue_1000' => $this->appointment($this->doctorA, $this->patient2, '2026-11-03', '10:00'),
            'a_sun_0900_cancelled' => $this->appointment($this->doctorA, $this->patient1, '2026-11-01', '09:00', [], cancelled: true),
        ];
    }

    public function test_a_range_lists_every_appointment_ordered_by_date_and_time(): void
    {
        $response = $this->list(['from' => '2026-11-01', 'to' => '2026-11-03'])->assertOk();

        $this->assertSame($this->ids('a_sun_0900_cancelled', 'a_sun_0920', 'b_sun_0940', 'a_tue_1000'), array_column($response->json('data'), 'id'));
        foreach ($response->json('data') as $item) {
            $this->assertSame(self::SUMMARY_KEYS, array_keys($item));
            $this->assertSame(['id', 'name', 'title'], array_keys($item['doctor']));
            $this->assertSame(['id', 'full_name', 'phone'], array_keys($item['patient']));
        }
    }

    public function test_lists_never_contain_the_note_or_sensitive_patient_fields(): void
    {
        $json = $this->list(['from' => '2026-11-01', 'to' => '2026-11-03'])->assertOk()->getContent();

        foreach (['note', 'national_id', 'address', 'emergency_contact', 'Synthetic note', 'Synthetic address', $this->patient1->national_id] as $text) {
            $this->assertStringNotContainsString($text, $json);
        }
    }

    public function test_the_range_limits_the_dates(): void
    {
        $this->assertSame($this->ids('a_tue_1000'), $this->listedIds(['from' => '2026-11-02', 'to' => '2026-11-03']));
    }

    /**
     * @return array<string, array{array<string, string>, list<string>}>
     */
    public static function filters(): array
    {
        return [
            'doctor' => [['doctor' => 'A'], ['a_sun_0900_cancelled', 'a_sun_0920', 'a_tue_1000']],
            'patient' => [['patient' => '1'], ['a_sun_0900_cancelled', 'a_sun_0920']],
            'status' => [['status' => 'cancelled'], ['a_sun_0900_cancelled']],
            'doctor and patient' => [['doctor' => 'A', 'patient' => '2'], ['a_tue_1000']],
            'doctor, patient, and status' => [['doctor' => 'A', 'patient' => '1', 'status' => 'scheduled'], ['a_sun_0920']],
        ];
    }

    /**
     * @param  array<string, string>  $filter
     * @param  list<string>  $expected
     */
    #[DataProvider('filters')]
    public function test_filters_combine(array $filter, array $expected): void
    {
        $query = ['from' => '2026-11-01', 'to' => '2026-11-03'];
        if (isset($filter['doctor'])) {
            $query['doctor_id'] = ($filter['doctor'] === 'A' ? $this->doctorA : $this->doctorB)->id;
        }
        if (isset($filter['patient'])) {
            $query['patient_id'] = ($filter['patient'] === '1' ? $this->patient1 : $this->patient2)->id;
        }
        if (isset($filter['status'])) {
            $query['status'] = $filter['status'];
        }

        $this->assertSame($this->ids(...$expected), $this->listedIds($query));
    }

    public function test_an_unknown_doctor_filter_matches_nothing(): void
    {
        $this->list(['from' => '2026-11-01', 'to' => '2026-11-03', 'doctor_id' => 999999])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_results_are_paged(): void
    {
        $this->list(['from' => '2026-11-01', 'to' => '2026-11-03', 'per_page' => 2])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 2);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'missing dates' => [[], 'from'],
            'to before from' => [['from' => '2026-11-05', 'to' => '2026-11-04'], 'to'],
            'more than 92 days' => [['from' => '2026-11-01', 'to' => '2027-02-01'], 'to'],
            'page size above 100' => [['from' => '2026-11-01', 'to' => '2026-11-03', 'per_page' => 101], 'per_page'],
            'unknown status' => [['from' => '2026-11-01', 'to' => '2026-11-03', 'status' => 'done'], 'status'],
            'not a boolean' => [['from' => '2026-11-01', 'to' => '2026-11-03', 'outside_availability' => 'maybe'], 'outside_availability'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidQueries')]
    public function test_invalid_queries_are_rejected(array $query, string $field): void
    {
        $this->list($query)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    public function test_the_range_limit_message_names_92_days(): void
    {
        $this->list(['from' => '2026-11-01', 'to' => '2027-02-01'])
            ->assertJsonPath('errors.to.0', __('appointments::messages.range_too_long', ['days' => 92]));
    }

    public function test_a_92_day_range_and_past_ranges_are_accepted(): void
    {
        $this->list(['from' => '2026-11-01', 'to' => '2027-01-31'])->assertOk()->assertJsonPath('meta.total', 4);
        $this->list(['from' => '2026-09-01', 'to' => '2026-10-31'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_one_appointment_shows_its_full_details(): void
    {
        $appointment = $this->appointments['b_sun_0940'];

        $response = $this->getJson("/api/v1/appointments/{$appointment->id}")->assertOk();

        $this->assertSame([...self::SUMMARY_KEYS, 'note', 'booked_by', 'booked_at', 'rescheduled_by', 'rescheduled_at', 'status_changed_by', 'status_changed_at', 'cancellation_reason'], array_keys($response->json('data')));
        $response->assertJsonPath('data.note', 'Synthetic note')
            ->assertJsonPath('data.booked_by.id', $appointment->booked_by)
            ->assertJsonPath('data.start', '09:40')
            ->assertJsonPath('data.end', '10:00');
    }

    public function test_a_cancelled_appointment_shows_who_cancelled_it(): void
    {
        $appointment = $this->appointments['a_sun_0900_cancelled'];

        $this->getJson("/api/v1/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.status_changed_by.id', $appointment->status_changed_by)
            ->assertJsonPath('data.cancellation_reason', 'Synthetic reason');
    }

    public function test_an_unknown_appointment_is_not_found(): void
    {
        $this->getJson('/api/v1/appointments/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => __('appointments::messages.not_found')]);
    }

    public function test_a_doctor_cannot_list_or_open_appointments(): void
    {
        Sanctum::actingAs($this->doctorA->user);

        $this->list(['from' => '2026-11-01', 'to' => '2026-11-03'])->assertForbidden();
        $this->getJson("/api/v1/appointments/{$this->appointments['a_sun_0920']->id}")->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function appointment(Doctor $doctor, Patient $patient, string $date, string $start, array $attributes = [], bool $cancelled = false): Appointment
    {
        $factory = Appointment::factory()->at($date, $start);

        return ($cancelled ? $factory->cancelled() : $factory)->create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function list(array $query): TestResponse
    {
        return $this->getJson('/api/v1/appointments?'.http_build_query($query));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function listedIds(array $query): array
    {
        return array_column($this->list($query)->assertOk()->json('data'), 'id');
    }

    /**
     * @return list<int>
     */
    private function ids(string ...$keys): array
    {
        return array_map(fn (string $key) => $this->appointments[$key]->id, $keys);
    }
}
