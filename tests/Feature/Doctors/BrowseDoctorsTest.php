<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * All staff list, search, and view doctors; a doctor fetches their own profile (US3,
 * FR-012–FR-017).
 */
class BrowseDoctorsTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY_KEYS = ['id', 'name', 'title', 'is_active', 'specialties'];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->receptionist()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readers(): array
    {
        return ['receptionist' => ['receptionist'], 'doctor' => ['doctor'], 'administrator' => ['administrator']];
    }

    #[DataProvider('readers')]
    public function test_every_role_lists_doctors_with_the_summary_shape_only(string $role): void
    {
        Doctor::factory()->withRegistrationNumber()->withSpecialties(2)->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $response = $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(1, 'data');

        $this->assertEqualsCanonicalizing(self::SUMMARY_KEYS, array_keys($response->json('data.0')));
        $this->assertCount(2, $response->json('data.0.specialties'));
    }

    public function test_the_specialty_filter_lists_only_doctors_with_it(): void
    {
        $cardiology = Specialty::factory()->create();
        $pediatrics = Specialty::factory()->create();
        $cardiologist = $this->doctorNamed('Cardio Doctor', [$cardiology]);
        $this->doctorNamed('Pediatric Doctor', [$pediatrics]);
        $both = $this->doctorNamed('Both Doctor', [$cardiology, $pediatrics]);

        $cardiology->update(['is_active' => false]);

        $this->assertListed("specialty_id={$cardiology->id}", [$both, $cardiologist]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nameSearches(): array
    {
        return [
            'latin case and spaces' => ['Mostafa  Ali Hassan', 'mostafa   ALI'],
            'alef without hamza finds hamza' => ['أحمد سمير', 'احمد'],
            'hamza finds alef without hamza' => ['احمد سمير', 'أحمد'],
            'haa finds taa marbuta' => ['فاطمة علي', 'فاطمه'],
            'yaa finds alef maqsura' => ['مصطفى كمال', 'مصطفي'],
            'alef maqsura finds yaa' => ['مصطفي كمال', 'مصطفى'],
            'hamza below and madda' => ['إيمان آمال', 'ايمان امال'],
        ];
    }

    #[DataProvider('nameSearches')]
    public function test_name_search_ignores_case_spaces_and_arabic_variants(string $name, string $term): void
    {
        $match = $this->doctorNamed($name);
        $this->doctorNamed('Unrelated Person');

        $this->assertListed('name='.urlencode($term), [$match]);
    }

    public function test_like_wildcards_in_the_search_term_match_literally(): void
    {
        $percent = $this->doctorNamed('Dr 100% Real');
        $this->doctorNamed('Dr 1000 Real');

        $this->assertListed('name='.urlencode('100%'), [$percent]);
        $this->assertListed('name='.urlencode('_'), []);
    }

    public function test_the_active_filter_follows_the_staff_account(): void
    {
        $active = $this->doctorNamed('Active Doctor');
        $deactivated = $this->doctorNamed('Deactivated Doctor');
        $moved = $this->doctorNamed('Moved Doctor');

        $deactivated->user->update(['is_active' => false]);
        $moved->user->update(['role' => Role::Receptionist]);

        $this->assertListed('is_active=1', [$active]);
        $response = $this->assertListed('is_active=0', [$deactivated, $moved]);
        $this->assertSame([false, false], array_column($response->json('data'), 'is_active'));

        $deactivated->user->update(['is_active' => true]);
        $moved->user->update(['role' => Role::Doctor]);

        $this->assertListed('is_active=1', [$active, $deactivated, $moved]);
    }

    public function test_filters_combine_and_results_are_ordered_by_name(): void
    {
        $specialty = Specialty::factory()->create();
        $zaki = $this->doctorNamed('Zaki Samir', [$specialty]);
        $adel = $this->doctorNamed('Adel Samir', [$specialty]);
        $this->doctorNamed('Basel Samir');
        $this->doctorNamed('Adel Fawzy', [$specialty]);

        $this->assertListed("name=samir&specialty_id={$specialty->id}", [$adel, $zaki]);
    }

    public function test_paging_defaults_to_15_and_caps_per_page_at_100(): void
    {
        Doctor::factory()->count(16)->withSpecialties()->create();

        $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 16);
        $this->getJson('/api/v1/doctors?per_page=100')->assertOk()->assertJsonCount(16, 'data');
        $this->getJson('/api/v1/doctors?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'specialty id' => ['specialty_id=abc'],
            'active status' => ['is_active=maybe'],
            'page' => ['page=0'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(string $query): void
    {
        $this->getJson("/api/v1/doctors?{$query}")->assertUnprocessable();
    }

    public function test_a_single_profile_adds_the_registration_number(): void
    {
        $doctor = Doctor::factory()->withSpecialties()->create(['registration_number' => 'A12345']);

        $response = $this->getJson("/api/v1/doctors/{$doctor->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_number', 'A12345')
            ->assertJsonPath('data.name', $doctor->user->name)
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.user_id');

        $this->assertEqualsCanonicalizing(
            [...self::SUMMARY_KEYS, 'registration_number', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );
    }

    public function test_an_unknown_doctor_is_not_found(): void
    {
        $this->getJson('/api/v1/doctors/999')->assertNotFound();
    }

    public function test_a_doctor_fetches_their_own_profile(): void
    {
        $doctor = Doctor::factory()->withSpecialties()->create();
        Doctor::factory()->withSpecialties()->create();
        Sanctum::actingAs($doctor->user);

        $this->getJson('/api/v1/doctors/me')->assertOk()->assertJsonPath('data.id', $doctor->id);
    }

    public function test_a_doctor_without_a_profile_gets_not_found(): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->getJson('/api/v1/doctors/me')->assertNotFound();
    }

    public function test_other_roles_cannot_use_the_own_profile_endpoint(): void
    {
        $this->getJson('/api/v1/doctors/me')->assertForbidden();
    }

    /**
     * @param  list<Specialty>  $specialties  defaults to one new specialty
     */
    private function doctorNamed(string $name, array $specialties = []): Doctor
    {
        $doctor = Doctor::factory()->create(['user_id' => User::factory()->doctor()->create(['name' => $name])]);
        $doctor->specialties()->attach($specialties === [] ? [Specialty::factory()->create()->id] : array_map(fn (Specialty $s) => $s->id, $specialties));

        return $doctor;
    }

    /**
     * Assert the list for this query returns exactly these doctors, in this order.
     *
     * @param  list<Doctor>  $expected
     */
    private function assertListed(string $query, array $expected): TestResponse
    {
        $response = $this->getJson("/api/v1/doctors?{$query}")->assertOk();

        $this->assertSame(
            array_map(fn (Doctor $d) => $d->id, $expected),
            array_column($response->json('data'), 'id'),
        );

        return $response;
    }
}
