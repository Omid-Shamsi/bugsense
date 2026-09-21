<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bug_id')->constrained('bugs')->restrictOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
        });

        DB::statement('ALTER TABLE progress_updates ADD CONSTRAINT progress_updates_body_nonblank CHECK (char_length(body) >= 1)');

        Schema::create('resolution_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bug_id')->constrained('bugs')->restrictOnDelete();
            $table->integer('attempt_number');
            $table->string('outcome', 20);
            $table->foreignUuid('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->string('source_status', 20);
            $table->text('explanation');
            $table->text('qa_instructions')->nullable();

            // No FK yet: bug_relationships does not exist until the next
            // migration. The FK is added there once the target table exists.
            $table->uuid('duplicate_relationship_id')->nullable();

            $table->text('reproduction_attempts')->nullable();
            $table->text('reproduction_environment')->nullable();
            $table->text('decision_rationale')->nullable();
            $table->timestamp('recorded_at');

            $table->unique(['bug_id', 'attempt_number']);
            // Enables the same-Bug composite FK from bugs.active_resolution_attempt_id.
            $table->unique(['bug_id', 'id']);
        });

        DB::statement('ALTER TABLE resolution_attempts ADD CONSTRAINT resolution_attempts_attempt_number_positive CHECK (attempt_number > 0)');
        DB::statement("ALTER TABLE resolution_attempts ADD CONSTRAINT resolution_attempts_outcome_check CHECK (outcome IN ('fixed', 'duplicate', 'cannot_reproduce', 'wont_fix'))");
        DB::statement('ALTER TABLE resolution_attempts ADD CONSTRAINT resolution_attempts_explanation_nonblank CHECK (char_length(explanation) >= 1)');

        // Outcome-specific required evidence (data-model.md ResolutionAttempt).
        DB::statement(
            'ALTER TABLE resolution_attempts ADD CONSTRAINT resolution_attempts_outcome_evidence CHECK ('.
            "(outcome = 'fixed' AND qa_instructions IS NOT NULL AND char_length(qa_instructions) >= 1) OR ".
            "(outcome = 'duplicate' AND duplicate_relationship_id IS NOT NULL) OR ".
            "(outcome = 'cannot_reproduce' AND reproduction_attempts IS NOT NULL AND char_length(reproduction_attempts) >= 1 ".
            'AND reproduction_environment IS NOT NULL AND char_length(reproduction_environment) >= 1) OR '.
            "(outcome = 'wont_fix' AND decision_rationale IS NOT NULL AND char_length(decision_rationale) >= 1)".
            ')'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('resolution_attempts');
        Schema::dropIfExists('progress_updates');
    }
};
