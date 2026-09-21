<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_verification_results', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Unique, not just indexed: one result per ResolutionAttempt,
            // enforced at the database level (data-model.md).
            $table->foreignUuid('resolution_attempt_id')->unique()->constrained('resolution_attempts')->restrictOnDelete();

            $table->foreignUuid('verifier_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 20);
            $table->text('verification_notes');
            $table->timestamp('created_at');
        });

        DB::statement("ALTER TABLE qa_verification_results ADD CONSTRAINT qa_verification_results_decision_check CHECK (decision IN ('approved', 'rejected'))");
        DB::statement('ALTER TABLE qa_verification_results ADD CONSTRAINT qa_verification_results_notes_nonblank CHECK (char_length(verification_notes) >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_verification_results');
    }
};
