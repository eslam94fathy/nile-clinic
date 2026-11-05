<?php

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The append-only trail's table (specs/011-audit-logs/data-model.md, research R10).
 */
class AuditEntriesTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_table_has_every_column(): void
    {
        $this->assertTrue(Schema::hasColumns('audit_entries', [
            'id', 'action', 'outcome', 'actor_type', 'actor_id', 'actor_role', 'subject_type',
            'subject_id', 'patient_id', 'network_address', 'details', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('audit_entries', 'updated_at'));
    }

    public function test_the_indexes_exist(): void
    {
        foreach ([
            'audit_entries_subject_index',
            'audit_entries_action_index',
            'audit_entries_created_at_index',
            'audit_entries_actor_id_foreign',
            'audit_entries_patient_id_foreign',
        ] as $index) {
            $this->assertTrue(Schema::hasIndex('audit_entries', $index), "Missing index {$index}.");
        }
    }

    public function test_an_entry_can_refer_to_a_record_that_no_longer_exists(): void
    {
        DB::table('audit_entries')->insert([
            'action' => 'visit.check_in_undone',
            'outcome' => 'succeeded',
            'actor_type' => 'system',
            'subject_type' => 'visit',
            'subject_id' => 999999,
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('audit_entries', ['subject_type' => 'visit', 'subject_id' => 999999]);
    }
}
