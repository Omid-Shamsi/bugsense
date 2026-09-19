<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bugs', function (Blueprint $table) {
            $table->uuid('active_resolution_attempt_id')->nullable();
        });

        // Same-Bug invariant enforced at the database level, not merely
        // trusted from controller input: this composite FK only allows
        // active_resolution_attempt_id to reference a resolution_attempts
        // row whose bug_id equals this Bug's own id.
        DB::statement(
            'ALTER TABLE bugs ADD CONSTRAINT bugs_active_attempt_same_bug '.
            'FOREIGN KEY (id, active_resolution_attempt_id) REFERENCES resolution_attempts (bug_id, id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bugs DROP CONSTRAINT bugs_active_attempt_same_bug');

        Schema::table('bugs', function (Blueprint $table) {
            $table->dropColumn('active_resolution_attempt_id');
        });
    }
};
