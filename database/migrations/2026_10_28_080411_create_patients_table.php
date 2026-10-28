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
        // Patients (specs/003-patient-records/data-model.md). The id is the patient number (R2);
        // name_search is a normalized copy of full_name for Arabic-aware search (R3).
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255);
            $table->string('name_search', 255);
            $table->date('date_of_birth');
            $table->string('sex', 10);
            $table->string('phone', 16);
            $table->char('national_id', 14)->nullable()->unique();
            $table->string('address', 500)->nullable();
            $table->string('emergency_contact_name', 255)->nullable();
            $table->string('emergency_contact_relationship', 50)->nullable();
            $table->string('emergency_contact_phone', 16)->nullable();
            $table->timestamps();

            $table->index('phone');
            $table->index('name_search');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
