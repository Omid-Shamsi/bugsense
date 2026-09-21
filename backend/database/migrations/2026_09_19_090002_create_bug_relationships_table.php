<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enables same-project composite FKs below (bugs.id is already
        // globally unique via its primary key, so this pair is trivially
        // unique too).
        Schema::table('bugs', function (Blueprint $table) {
            $table->unique(['project_id', 'id']);
        });

        Schema::create('bug_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->uuid('source_bug_id');
            $table->uuid('target_bug_id');
            $table->string('relationship_type', 20);

            // Only used once related_to is added in a later phase; present
            // now to match the approved schema without a future ALTER.
            $table->uuid('canonical_low_bug_id')->nullable();
            $table->uuid('canonical_high_bug_id')->nullable();

            $table->foreignUuid('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->boolean('is_active')->default(true);
        });

        DB::statement("ALTER TABLE bug_relationships ADD CONSTRAINT bug_relationships_type_check CHECK (relationship_type IN ('duplicate_of'))");
        DB::statement('ALTER TABLE bug_relationships ADD CONSTRAINT bug_relationships_no_self_link CHECK (source_bug_id != target_bug_id)');

        // Same-project integrity for both endpoints.
        DB::statement(
            'ALTER TABLE bug_relationships ADD CONSTRAINT bug_relationships_source_same_project '.
            'FOREIGN KEY (project_id, source_bug_id) REFERENCES bugs (project_id, id) ON DELETE RESTRICT'
        );
        DB::statement(
            'ALTER TABLE bug_relationships ADD CONSTRAINT bug_relationships_target_same_project '.
            'FOREIGN KEY (project_id, target_bug_id) REFERENCES bugs (project_id, id) ON DELETE RESTRICT'
        );

        // Unique active directional link per type (duplicate_of/blocks shape).
        DB::statement(
            'CREATE UNIQUE INDEX bug_relationships_active_directional_unique ON bug_relationships '.
            '(relationship_type, source_bug_id, target_bug_id) WHERE is_active'
        );

        Schema::table('bug_relationships', function (Blueprint $table) {
            $table->index(['target_bug_id', 'relationship_type']);
        });

        // Now that bug_relationships exists, back-fill the FK deferred from
        // the previous migration.
        Schema::table('resolution_attempts', function (Blueprint $table) {
            $table->foreign('duplicate_relationship_id')->references('id')->on('bug_relationships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('resolution_attempts', function (Blueprint $table) {
            $table->dropForeign(['duplicate_relationship_id']);
        });

        Schema::dropIfExists('bug_relationships');

        Schema::table('bugs', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'id']);
        });
    }
};
