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
        // Money received on a charge (specs/009-payments/data-model.md).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->constrained('charges')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 20)->comment('cash | card | wallet');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
