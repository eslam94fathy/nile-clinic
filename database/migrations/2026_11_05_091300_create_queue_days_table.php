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
        // Queue days (specs/007-patient-queues/data-model.md): locked for every queue change.
        Schema::create('queue_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['doctor_id', 'date'], 'queue_days_doctor_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_days');
    }
};
