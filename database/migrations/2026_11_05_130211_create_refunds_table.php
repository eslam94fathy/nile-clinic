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
        // Money returned on a charge (specs/009-payments/data-model.md).
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->constrained('charges')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 20)->comment('cash | card | wallet');
            $table->string('reason', 500);
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
