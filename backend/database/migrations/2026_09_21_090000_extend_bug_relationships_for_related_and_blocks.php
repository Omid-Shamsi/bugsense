<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bug_relationships DROP CONSTRAINT bug_relationships_type_check');
        DB::statement("ALTER TABLE bug_relationships ADD CONSTRAINT bug_relationships_type_check CHECK (relationship_type IN ('duplicate_of', 'related_to', 'blocks'))");

        // related_to must store BOTH canonical endpoints together (data-model.md).
        DB::statement(
            'ALTER TABLE bug_relationships ADD CONSTRAINT bug_relationships_related_to_canonical CHECK ('.
            "(relationship_type = 'related_to' AND canonical_low_bug_id IS NOT NULL AND canonical_high_bug_id IS NOT NULL) OR ".
            "(relationship_type != 'related_to')".
            ')'
        );

        // Unique active canonical pair for Related-to: A~B and B~A collapse to one row.
        DB::statement(
            'CREATE UNIQUE INDEX bug_relationships_active_canonical_unique ON bug_relationships '.
            "(canonical_low_bug_id, canonical_high_bug_id) WHERE is_active AND relationship_type = 'related_to'"
        );

        Schema::table('bug_relationships', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignUuid('deactivated_by_id')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bug_relationships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by_id');
            $table->dropColumn('deactivated_at');
        });

        DB::statement('DROP INDEX IF EXISTS bug_relationships_active_canonical_unique');
        DB::statement('ALTER TABLE bug_relationships DROP CONSTRAINT bug_relationships_related_to_canonical');

        DB::statement('ALTER TABLE bug_relationships DROP CONSTRAINT bug_relationships_type_check');
        DB::statement("ALTER TABLE bug_relationships ADD CONSTRAINT bug_relationships_type_check CHECK (relationship_type IN ('duplicate_of'))");
    }
};
