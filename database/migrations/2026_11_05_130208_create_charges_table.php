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
        // What one visit costs (specs/009-payments/data-model.md).
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_entry_id')->nullable()->constrained('queue_entries')->nullOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->date('date');
            $table->string('status', 20)->comment('unpaid | partially_paid | paid | cancelled');
            $table->unsignedTinyInteger('active_flag')->nullable()->storedAs("IF(status <> 'cancelled', 1, NULL)");
            $table->decimal('fee', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('discount_reason', 500)->nullable();
            $table->foreignId('discounted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('discounted_at')->nullable();
            $table->foreignId('charged_by')->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['queue_entry_id', 'active_flag'], 'charges_visit_active_unique');
            $table->index(['date', 'id'], 'charges_date_index');
            $table->index(['patient_id', 'date', 'id'], 'charges_patient_index');
            $table->index(['doctor_id', 'date', 'id'], 'charges_doctor_index');
        });

        DB::statement('ALTER TABLE charges ADD CONSTRAINT charges_money_check CHECK (discount >= 0 AND amount_paid >= 0 AND discount + amount_paid <= fee AND fee > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
