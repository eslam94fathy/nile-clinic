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
        // Doctor specialty assignments (specs/004-doctors-specialties/data-model.md). Neither side
        // is ever deleted, so both foreign keys restrict (R6).
        Schema::create('doctor_specialty', function (Blueprint $table) {
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('specialty_id')->constrained('specialties')->restrictOnDelete();

            $table->primary(['doctor_id', 'specialty_id']);
            $table->index('specialty_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_specialty');
    }
};
