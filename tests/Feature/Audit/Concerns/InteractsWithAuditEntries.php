<?php

namespace Tests\Feature\Audit\Concerns;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Audit\Repositories\Contracts\AuditEntryRepository;
use App\Modules\Audit\Repositories\EloquentAuditEntryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use RuntimeException;

trait InteractsWithAuditEntries
{
    /**
     * Exactly one entry has this action, and it matches each expected attribute.
     *
     * @param  array<string, mixed>  $expected
     */
    protected function assertOneEntry(AuditAction $action, array $expected = []): AuditEntry
    {
        $entries = AuditEntry::query()->where('action', $action)->get();

        $this->assertCount(1, $entries, "Expected exactly one {$action->value} entry.");

        $entry = $entries->first();

        foreach ($expected as $attribute => $value) {
            $this->assertEquals($value, $entry->{$attribute}, "{$action->value}: unexpected {$attribute}.");
        }

        return $entry;
    }

    protected function assertNoEntries(AuditAction $action): void
    {
        $this->assertSame(0, AuditEntry::query()->where('action', $action)->count(), "Expected no {$action->value} entry.");
    }

    /**
     * Saving an entry now fails; everything else still works. Call this before the test's first
     * request to the route, because Laravel caches controllers per route.
     */
    protected function failAuditWrites(): void
    {
        $real = new EloquentAuditEntryRepository;

        $this->app->bind(AuditEntryRepository::class, fn () => new class($real) implements AuditEntryRepository
        {
            public function __construct(private readonly AuditEntryRepository $real) {}

            public function append(array $attributes): AuditEntry
            {
                throw new RuntimeException('Forced audit failure.');
            }

            public function search(array $filters, ?CarbonImmutable $start, ?CarbonImmutable $end, int $perPage): CursorPaginator
            {
                return $this->real->search($filters, $start, $end, $perPage);
            }
        });
    }
}
