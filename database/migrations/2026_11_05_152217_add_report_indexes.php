<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// See specs/010-reports/data-model.md.
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index('created_at', 'payments_created_at_index');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->index('created_at', 'refunds_created_at_index');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->index('created_at', 'patients_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_created_at_index');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex('refunds_created_at_index');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_created_at_index');
        });
    }
};
