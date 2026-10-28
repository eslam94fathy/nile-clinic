<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Working periods of a weekly schedule (specs/005-scheduling-availability/data-model.md).
        // Weekday 0 = Sunday; times are minutes since midnight, wall clock (R3). Replaced as a set
        // and deleted with their schedule.
        Schema::create('schedule_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_schedule_id')->constrained('doctor_schedules')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');

            $table->index(['doctor_schedule_id', 'weekday'], 'schedule_periods_schedule_weekday_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_periods');
    }
};
