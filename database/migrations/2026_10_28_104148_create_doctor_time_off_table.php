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
        // Doctor time off (specs/005-scheduling-availability/data-model.md): whole days when the
        // minutes are null, otherwise part of one day; wall-clock dates and minutes (R7).
        Schema::create('doctor_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('start_minute')->nullable();
            $table->unsignedSmallInteger('end_minute')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'start_date', 'end_date'], 'doctor_time_off_doctor_dates_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_time_off');
    }
};
