<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_scope', 20);
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->restrictOnDelete();

            // bug_id intentionally has no foreign key yet: the bugs table does not
            // exist until the Bug domain phase. The column and its uniqueness
            // constraint are added now so later Bug work only needs to attach a
            // foreign key, not restructure this table.
            $table->uuid('bug_id')->nullable();
            $table->integer('sequence')->nullable();

            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();

            $table->string('event_type', 60);
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_display', 150);
            $table->timestamp('occurred_at');
            $table->uuid('command_id');
            $table->jsonb('before_data')->nullable();
            $table->jsonb('after_data')->nullable();
            $table->text('reason_or_result')->nullable();
        });

        DB::statement("ALTER TABLE activity_events ADD CONSTRAINT activity_events_scope_check CHECK (event_scope IN ('bug', 'administration'))");

        // Exactly one of bug_id or the administration subject pair is present.
        DB::statement(
            'ALTER TABLE activity_events ADD CONSTRAINT activity_events_subject_check CHECK ('.
            'num_nonnulls(bug_id, subject_id) = 1'.
            ')'
        );

        DB::statement(
            'CREATE UNIQUE INDEX activity_events_bug_sequence_unique ON activity_events (bug_id, sequence) '.
            'WHERE bug_id IS NOT NULL'
        );

        Schema::table('activity_events', function (Blueprint $table) {
            $table->index(['project_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
