<?php

namespace Tests\Feature\Encounters;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only active doctors use medical records; which records they read is the approved access rule
 * (FR-012–FR-014, research R4).
 */
class EncountersPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->reader = Doctor::factory()->create();
    }

    /**
     * @return array<string, array{Role, bool}>
     */
    public static function roles(): array
    {
        return [
            'administrator' => [Role::Administrator, false],
            'receptionist' => [Role::Receptionist, false],
            'doctor' => [Role::Doctor, true],
        ];
    }

    #[DataProvider('roles')]
    public function test_only_active_doctors_have_access(Role $role, bool $allowed): void
    {
        $active = User::factory()->create(['role' => $role]);
        $inactive = User::factory()->inactive()->create(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($active)->allows('access', Encounter::class));
        $this->assertFalse(Gate::forUser($inactive)->allows('access', Encounter::class));
    }

    public function test_a_doctor_reads_their_own_draft_and_finalized_records_without_a_list_entry(): void
    {
        $draft = Encounter::factory()->forEntry($this->ownEntry())->create();
        $finalized = Encounter::factory()->forEntry($this->ownEntry(2))->finalized()->create();

        $this->assertTrue(Gate::forUser($this->reader->user)->allows('view', $draft));
        $this->assertTrue(Gate::forUser($this->reader->user)->allows('view', $finalized));
    }

    public function test_another_doctors_draft_is_never_readable(): void
    {
        $draft = Encounter::factory()->create();
        QueueEntry::factory()->create(['doctor_id' => $this->reader->id, 'patient_id' => $draft->patient_id]);

        $this->assertFalse(Gate::forUser($this->reader->user)->allows('view', $draft));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function listStates(): array
    {
        return [
            'waiting' => ['waiting', true],
            'with doctor' => ['withDoctor', true],
            'seen' => ['seen', true],
            'left' => ['left', false],
        ];
    }

    #[DataProvider('listStates')]
    public function test_another_doctors_finalized_record_follows_todays_list(string $state, bool $allowed): void
    {
        $record = Encounter::factory()->finalized()->create();
        $factory = QueueEntry::factory();
        $factory = $state === 'waiting' ? $factory : $factory->{$state}();
        $factory->create(['doctor_id' => $this->reader->id, 'patient_id' => $record->patient_id]);

        $this->assertSame($allowed, Gate::forUser($this->reader->user)->allows('view', $record));
    }

    public function test_another_doctors_finalized_record_is_refused_without_a_list_entry(): void
    {
        $record = Encounter::factory()->finalized()->create();

        $this->assertFalse(Gate::forUser($this->reader->user)->allows('view', $record));
    }

    public function test_yesterdays_list_gives_no_access(): void
    {
        $record = Encounter::factory()->finalized()->create();
        QueueEntry::factory()->withDoctor()->create([
            'doctor_id' => $this->reader->id,
            'patient_id' => $record->patient_id,
            'date' => CarbonImmutable::parse(ClinicClock::today())->subDay()->toDateString(),
        ]);

        $this->assertFalse(Gate::forUser($this->reader->user)->allows('view', $record));
    }

    public function test_a_doctor_without_a_profile_reads_nothing(): void
    {
        $record = Encounter::factory()->finalized()->create();

        $this->assertFalse(Gate::forUser(User::factory()->doctor()->create())->allows('view', $record));
    }

    public function test_only_the_author_writes(): void
    {
        $own = Encounter::factory()->forEntry($this->ownEntry())->finalized()->create();
        $other = Encounter::factory()->finalized()->create();

        $this->assertTrue(Gate::forUser($this->reader->user)->allows('write', $own));
        $this->assertFalse(Gate::forUser($this->reader->user)->allows('write', $other));
    }

    public function test_a_deactivated_author_can_neither_read_nor_write(): void
    {
        $own = Encounter::factory()->forEntry($this->ownEntry())->finalized()->create();
        $this->reader->user->update(['is_active' => false]);

        $this->assertFalse(Gate::forUser($this->reader->user)->allows('view', $own));
        $this->assertFalse(Gate::forUser($this->reader->user)->allows('write', $own));
    }

    private function ownEntry(int $number = 1): QueueEntry
    {
        return QueueEntry::factory()->number($number)->seen()->create(['doctor_id' => $this->reader->id]);
    }
}
