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
        // Appointments (specs/006-appointments/data-model.md): wall-clock date and minutes; the
        // generated scheduled_flag makes the unique index apply to scheduled rows only (R3).
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->string('status', 20)->comment('scheduled | cancelled | no_show');
            $table->unsignedTinyInteger('scheduled_flag')->nullable()->storedAs("IF(status = 'scheduled', 1, NULL)");
            $table->string('note', 500)->nullable();
            $table->foreignId('booked_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('rescheduled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rescheduled_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['doctor_id', 'date', 'start_minute', 'scheduled_flag'], 'appointments_doctor_slot_unique');
            $table->index(['patient_id', 'date', 'start_minute'], 'appointments_patient_date_index');
            $table->index(['date', 'start_minute'], 'appointments_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
