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
        // Queue entries (specs/007-patient-queues/data-model.md).
        Schema::create('queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->restrictOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('queue_number');
            $table->unsignedSmallInteger('order_minute');
            $table->string('status', 20)->comment('waiting | with_doctor | seen | left');
            $table->unsignedTinyInteger('with_doctor_flag')->nullable()->storedAs("IF(status = 'with_doctor', 1, NULL)");
            $table->timestamp('arrived_at');
            $table->foreignId('checked_in_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->foreignId('left_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['doctor_id', 'date', 'queue_number'], 'queue_entries_doctor_number_unique');
            $table->unique(['appointment_id'], 'queue_entries_appointment_unique');
            $table->unique(['doctor_id', 'date', 'with_doctor_flag'], 'queue_entries_doctor_with_doctor_unique');
            $table->unique(['patient_id', 'date', 'with_doctor_flag'], 'queue_entries_patient_with_doctor_unique');
            $table->index(['doctor_id', 'date', 'order_minute', 'queue_number'], 'queue_entries_doctor_order_index');
            $table->index(['date', 'doctor_id'], 'queue_entries_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
    }
};
