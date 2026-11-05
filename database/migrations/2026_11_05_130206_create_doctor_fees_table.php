<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Doctors' current consultation fees (specs/009-payments/data-model.md).
        Schema::create('doctor_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['doctor_id'], 'doctor_fees_doctor_unique');
        });

        DB::statement('ALTER TABLE doctor_fees ADD CONSTRAINT doctor_fees_amount_check CHECK (amount > 0 AND amount <= 100000)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_fees');
    }
};
