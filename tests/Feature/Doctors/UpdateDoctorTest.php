<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Administrators update doctor profiles; the linked account never changes (US2, FR-007–FR-010).
 */
class UpdateDoctorTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->administrator()->create());
        $this->doctor = Doctor::factory()->withSpecialties(2)->create(['title' => 'Specialist']);
    }

    public function test_changing_the_title_keeps_the_specialties(): void
    {
        $this->send(['title' => 'Consultant'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Consultant')
            ->assertJsonCount(2, 'data.specialties');

        $this->assertDatabaseHas('doctors', ['id' => $this->doctor->id, 'title' => 'Consultant']);
    }

    public function test_the_registration_number_can_be_set_kept_and_cleared(): void
    {
        $this->send(['registration_number' => 'B777'])->assertOk()->assertJsonPath('data.registration_number', 'B777');
        $this->send(['registration_number' => 'B777'])->assertOk();
        $this->send(['registration_number' => null])->assertOk()->assertJsonPath('data.registration_number', null);

        $this->assertDatabaseHas('doctors', ['id' => $this->doctor->id, 'registration_number' => null]);
    }

    public function test_another_doctors_registration_number_is_rejected(): void
    {
        Doctor::factory()->withSpecialties()->create(['registration_number' => 'C888']);

        $this->send(['registration_number' => 'c888'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration_number']);
    }

    public function test_sent_specialties_replace_the_set(): void
    {
        $new = Specialty::factory()->create();

        $this->send(['specialty_ids' => [$new->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.specialties')
            ->assertJsonPath('data.specialties.0.id', $new->id);

        $this->assertDatabaseCount('doctor_specialty', 1);
    }

    public function test_an_assigned_specialty_stays_after_it_is_deactivated(): void
    {
        $kept = $this->doctor->specialties->first();
        $kept->update(['is_active' => false]);

        $response = $this->send(['specialty_ids' => $this->doctor->specialties->modelKeys()])->assertOk();

        $this->assertContains(
            ['id' => $kept->id, 'is_active' => false],
            array_map(fn (array $s) => ['id' => $s['id'], 'is_active' => $s['is_active']], $response->json('data.specialties')),
        );
    }

    public function test_a_deactivated_specialty_cannot_be_newly_added(): void
    {
        $inactive = Specialty::factory()->inactive()->create();

        $this->send(['specialty_ids' => [...$this->doctor->specialties->modelKeys(), $inactive->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['specialty_ids' => __('doctors::messages.specialty_inactive')]);

        $this->assertDatabaseCount('doctor_specialty', 2);
    }

    public function test_the_last_specialty_cannot_be_removed(): void
    {
        $this->send(['specialty_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['specialty_ids']);

        $this->assertDatabaseCount('doctor_specialty', 2);
    }

    public function test_the_linked_account_cannot_be_changed(): void
    {
        $other = User::factory()->doctor()->create();

        $this->send(['title' => 'Consultant', 'user_id' => $other->id])->assertOk();

        $this->assertDatabaseHas('doctors', ['id' => $this->doctor->id, 'user_id' => $this->doctor->user_id]);
    }

    public function test_an_empty_update_is_rejected(): void
    {
        $this->send([])->assertUnprocessable()->assertJsonValidationErrors(['fields']);
    }

    public function test_an_unknown_doctor_is_not_found(): void
    {
        $this->patchJson('/api/v1/doctors/999', ['title' => 'Consultant'])->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(array $body): TestResponse
    {
        return $this->patchJson("/api/v1/doctors/{$this->doctor->id}", $body);
    }
}
