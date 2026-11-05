<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Checked-in and completed appointments keep their slot (specs/007-patient-queues/research.md R2).
        DB::statement("ALTER TABLE appointments MODIFY scheduled_flag TINYINT UNSIGNED AS (IF(status IN ('scheduled', 'checked_in', 'completed'), 1, NULL)) STORED NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE appointments MODIFY scheduled_flag TINYINT UNSIGNED AS (IF(status = 'scheduled', 1, NULL)) STORED NULL");
    }
};
