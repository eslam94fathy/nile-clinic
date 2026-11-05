<?php

namespace Tests\Feature\Payments;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administrators set each doctor's fee; reception reads it (US1, FR-001, FR-002).
 */
class DoctorFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $receptionist;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->admin = User::factory()->administrator()->create();
        $this->receptionist = User::factory()->receptionist()->create();
        $this->doctor = Doctor::factory()->create();
    }

    public function test_an_administrator_sets_a_fee(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->setFee('350.00')->assertOk();

        $this->assertSame([
            'doctor' => ['id' => $this->doctor->id, 'name' => $this->doctor->user->name, 'title' => $this->doctor->title],
            'amount' => '350.00',
            'set_at' => now()->toISOString(),
            'set_by' => ['id' => $this->admin->id, 'name' => $this->admin->name],
        ], $response->json('data'));

        $this->assertSame(1, DoctorFee::query()->count());
    }

    public function test_changing_a_fee_keeps_one_row_with_the_new_amount(): void
    {
        Sanctum::actingAs($this->admin);

        $this->setFee('350.00')->assertOk();
        $this->travel(1)->hours();
        $this->setFee(400.5)->assertOk()->assertJsonPath('data.amount', '400.50')->assertJsonPath('data.set_at', now()->toISOString());

        $this->assertSame(1, DoctorFee::query()->count());
        $this->assertSame('400.50', DoctorFee::query()->sole()->amount);
    }

    public function test_reception_and_administrators_read_a_fee(): void
    {
        DoctorFee::factory()->create(['doctor_id' => $this->doctor->id, 'amount' => '275.25']);

        foreach ([$this->admin, $this->receptionist] as $actor) {
            Sanctum::actingAs($actor);

            $this->getJson($this->url())->assertOk()->assertJsonPath('data.amount', '275.25');
        }
    }

    public function test_a_doctor_with_no_fee_shows_none(): void
    {
        Sanctum::actingAs($this->receptionist);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.doctor.id', $this->doctor->id)
            ->assertJsonPath('data.amount', null)
            ->assertJsonPath('data.set_at', null)
            ->assertJsonPath('data.set_by', null);
    }

    public function test_the_list_has_every_doctor_by_name_with_their_fee(): void
    {
        $this->doctor->user->update(['name' => 'Charlie Synthetic']);
        $first = Doctor::factory()->create();
        $first->user->update(['name' => 'Alice Synthetic']);
        $second = Doctor::factory()->create();
        $second->user->update(['name' => 'Bob Synthetic']);
        DoctorFee::factory()->create(['doctor_id' => $first->id, 'amount' => '100.00']);

        Sanctum::actingAs($this->receptionist);

        $data = $this->getJson('/api/v1/fees')->assertOk()->assertJsonPath('meta.total', 3)->json('data');

        $this->assertSame([$first->id, $second->id, $this->doctor->id], array_column(array_column($data, 'doctor'), 'id'));
        $this->assertSame(['100.00', null, null], array_column($data, 'amount'));

        $this->getJson('/api/v1/fees?per_page=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/fees?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badAmounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'three decimals' => [1.999],
            'three decimals as text' => ['1.999'],
            'not a number' => ['abc'],
            'above the limit' => [100000.01],
            'empty' => [null],
        ];
    }

    #[DataProvider('badAmounts')]
    public function test_a_bad_amount_is_refused_and_nothing_changes(mixed $amount): void
    {
        Sanctum::actingAs($this->admin);

        $this->setFee($amount)->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, DoctorFee::query()->count());
    }

    public function test_the_amount_is_required(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson($this->url(), [])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    }

    public function test_the_limits_are_accepted(): void
    {
        Sanctum::actingAs($this->admin);

        $this->setFee(100000)->assertOk()->assertJsonPath('data.amount', '100000.00');
        $this->setFee(0.01)->assertOk()->assertJsonPath('data.amount', '0.01');
    }

    public function test_an_unknown_doctor_is_not_found(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/doctors/999999/fee')->assertNotFound();
        $this->putJson('/api/v1/doctors/999999/fee', ['amount' => '100.00'])->assertNotFound();
    }

    public function test_only_administrators_set_a_fee(): void
    {
        foreach ([$this->receptionist, $this->doctor->user] as $actor) {
            Sanctum::actingAs($actor);

            $this->setFee('350.00')->assertForbidden();
            $this->setFee('not a number')->assertForbidden();
        }

        $this->assertSame(0, DoctorFee::query()->count());
    }

    public function test_doctors_cannot_read_fees(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->getJson($this->url())->assertForbidden();
        $this->getJson('/api/v1/fees')->assertForbidden();
        $this->getJson('/api/v1/doctors/999999/fee')->assertForbidden();
    }

    private function url(): string
    {
        return "/api/v1/doctors/{$this->doctor->id}/fee";
    }

    private function setFee(mixed $amount): TestResponse
    {
        return $this->putJson($this->url(), ['amount' => $amount]);
    }
}
