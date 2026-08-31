<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who did what, to which record, when.
     *
     * Several of the Phase 8 findings were only ever preventable, never
     * detectable: nothing recorded that a patient delete was attempted,
     * that an invoice was voided, or that a payment was taken. This is the
     * detection half.
     *
     * Append-only, like the clinical and financial ledgers it watches —
     * no updated_at, and no update or delete path anywhere in the app.
     *
     * `user_id` is nullable and nullOnDelete, not cascade: a deleted staff
     * account must not take the record of what it did with it. (Profile
     * deletion is refused while a user has authored anything, but the log
     * outlives even the accounts it can't hold onto.)
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Denormalised so a row still says who did it after the account
            // is gone, and so reading the log never needs a join.
            $table->string('actor_name')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            // What changed, as the caller chose to describe it. Never a
            // blind dump of the model's attributes — see AuditLog::record().
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'id']);
            $table->index('action');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
