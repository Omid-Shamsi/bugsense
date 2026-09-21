<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same-project category/priority/severity references are enforced with
        // composite foreign keys below; those require a unique (project_id, id)
        // pair on tracking_values, which its own primary key already guarantees.
        Schema::table('tracking_values', function (Blueprint $table) {
            $table->unique(['project_id', 'id']);
        });

        Schema::create('bugs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Stable, sequence-backed human-facing number (rendered as e.g.
            // BUG-000123 by the model). Independent of the UUID primary key so
            // it can be a plain always-increasing, gap-tolerant integer.
            $table->bigInteger('public_number')->unique();

            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('reporter_id')->constrained('users')->restrictOnDelete();

            // No FK-level active-Developer eligibility check yet: assignment
            // rules and their invariants belong to T032.
            $table->foreignUuid('assignee_membership_id')->nullable()->constrained('memberships')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('description');
            $table->text('steps_to_reproduce')->nullable();
            $table->text('expected_result')->nullable();
            $table->text('actual_result')->nullable();
            $table->text('environment')->nullable();
            $table->string('platform')->nullable();
            $table->string('application_version')->nullable();

            $table->uuid('category_id')->nullable();
            $table->uuid('priority_id')->nullable();
            $table->uuid('severity_id')->nullable();

            $table->string('status', 20)->default('submitted');

            $table->timestamps();
        });

        DB::statement('CREATE SEQUENCE bugs_public_number_seq OWNED BY bugs.public_number');
        DB::statement("ALTER TABLE bugs ALTER COLUMN public_number SET DEFAULT nextval('bugs_public_number_seq')");

        DB::statement('ALTER TABLE bugs ADD CONSTRAINT bugs_title_length CHECK (char_length(title) BETWEEN 1 AND 200)');
        DB::statement("ALTER TABLE bugs ADD CONSTRAINT bugs_description_nonblank CHECK (char_length(description) >= 1)");
        DB::statement(
            "ALTER TABLE bugs ADD CONSTRAINT bugs_status_check CHECK (status IN ".
            "('submitted', 'review', 'assigned', 'in_progress', 'resolved', 'qa_verification', 'closed', 'needs_information', 'reopened'))"
        );

        // Same-project integrity for classification references: a category,
        // priority, or severity value must belong to the same project as the Bug.
        DB::statement(
            'ALTER TABLE bugs ADD CONSTRAINT bugs_category_same_project '.
            'FOREIGN KEY (project_id, category_id) REFERENCES tracking_values (project_id, id)'
        );
        DB::statement(
            'ALTER TABLE bugs ADD CONSTRAINT bugs_priority_same_project '.
            'FOREIGN KEY (project_id, priority_id) REFERENCES tracking_values (project_id, id)'
        );
        DB::statement(
            'ALTER TABLE bugs ADD CONSTRAINT bugs_severity_same_project '.
            'FOREIGN KEY (project_id, severity_id) REFERENCES tracking_values (project_id, id)'
        );

        Schema::create('bug_tags', function (Blueprint $table) {
            $table->foreignUuid('bug_id')->constrained('bugs')->cascadeOnDelete();
            $table->foreignUuid('tracking_value_id')->constrained('tracking_values')->restrictOnDelete();
            $table->foreignUuid('added_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('added_at');

            $table->primary(['bug_id', 'tracking_value_id']);
        });

        // The Bug domain now exists: give activity_events.bug_id its foreign key.
        // (Its column, CHECK, and per-bug unique(bug_id, sequence) index were
        // already created in Phase 3, ahead of the Bug table, per T015.)
        Schema::table('activity_events', function (Blueprint $table) {
            $table->foreign('bug_id')->references('id')->on('bugs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activity_events', function (Blueprint $table) {
            $table->dropForeign(['bug_id']);
        });

        Schema::dropIfExists('bug_tags');
        Schema::dropIfExists('bugs');
        DB::statement('DROP SEQUENCE IF EXISTS bugs_public_number_seq');

        Schema::table('tracking_values', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'id']);
        });
    }
};
