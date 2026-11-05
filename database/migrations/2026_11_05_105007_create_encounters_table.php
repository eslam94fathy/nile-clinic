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
        // Medical records (specs/008-medical-encounters/data-model.md).
        Schema::create('encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_entry_id')->constrained('queue_entries')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->date('date');
            $table->string('status', 20)->comment('draft | finalized');
            $table->mediumText('chief_complaint')->nullable();
            $table->mediumText('examination_findings')->nullable();
            $table->mediumText('diagnosis')->nullable();
            $table->mediumText('treatment_plan')->nullable();
            $table->mediumText('prescriptions')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['queue_entry_id'], 'encounters_queue_entry_unique');
            $table->index(['patient_id', 'status', 'date', 'id'], 'encounters_patient_history_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('encounters');
    }
};
