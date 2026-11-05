<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Audit\Repositories\Contracts\AuditEntryRepository;
use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * FR-014, US4 scenario 1: no role has any way to change or delete an entry, and entries outlive what they
 * refer to.
 */
class AuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_request_can_write_to_the_trail(): void
    {
        $entry = AuditEntry::factory()->create();
        $before = $entry->fresh()->getAttributes();
        Sanctum::actingAs(User::factory()->administrator()->create());

        foreach (['/api/v1/audit-entries', "/api/v1/audit-entries/{$entry->id}"] as $url) {
            foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
                $this->assertContains($this->{$method}($url, ['outcome' => 'refused'])->getStatusCode(), [404, 405], "{$method} {$url}");
            }
        }

        $this->assertSame($before, $entry->fresh()->getAttributes());
    }

    public function test_only_one_route_exists_and_it_only_reads(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route) => str_contains($route->uri(), 'audit-entries'),
        ));

        $this->assertCount(1, $routes);
        $this->assertSame(['GET', 'HEAD'], $routes[0]->methods());
    }

    public function test_the_repository_can_only_append_and_search(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            (new ReflectionClass(AuditEntryRepository::class))->getMethods(),
        );

        $this->assertEqualsCanonicalizing(['append', 'search'], $methods);
    }

    public function test_entries_remain_when_their_staff_member_is_deactivated(): void
    {
        $member = User::factory()->receptionist()->create();
        $entry = AuditEntry::factory()->create(['actor_id' => $member->id]);

        $member->update(['is_active' => false]);

        $this->assertSame($member->id, $entry->fresh()->actor_id);
    }

    public function test_a_staff_member_with_entries_cannot_be_deleted(): void
    {
        $member = User::factory()->receptionist()->create();
        AuditEntry::factory()->create(['actor_id' => $member->id]);

        $this->expectException(QueryException::class);

        $member->delete();
    }

    public function test_a_patient_with_entries_cannot_be_deleted(): void
    {
        $patient = Patient::factory()->create();
        AuditEntry::factory()->create(['patient_id' => $patient->id]);

        $this->expectException(QueryException::class);

        $patient->delete();
    }
}
