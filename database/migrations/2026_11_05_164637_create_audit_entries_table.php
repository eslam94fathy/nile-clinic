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
        // The append-only audit trail (specs/011-audit-logs/data-model.md).
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->string('outcome', 10)->comment('succeeded | refused');
            $table->string('actor_type', 10)->comment('staff | system | unknown');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20)->nullable();
            $table->string('subject_type', 20)->nullable();
            // Deliberately no foreign key: entries outlive the records they describe (research R10).
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->restrictOnDelete();
            $table->string('network_address', 45)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id'], 'audit_entries_subject_index');
            $table->index('action', 'audit_entries_action_index');
            $table->index('created_at', 'audit_entries_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
