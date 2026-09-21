<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('information_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bug_id')->constrained('bugs')->restrictOnDelete();
            $table->foreignUuid('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->text('request_text');
            $table->timestamp('requested_at');
            $table->foreignUuid('responded_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('response_text')->nullable();
            $table->timestamp('responded_at')->nullable();
        });

        DB::statement('ALTER TABLE information_requests ADD CONSTRAINT information_requests_request_nonblank CHECK (char_length(request_text) >= 1)');
        DB::statement(
            'ALTER TABLE information_requests ADD CONSTRAINT information_requests_response_complete CHECK ('.
            '(responded_at IS NULL AND responded_by_id IS NULL AND response_text IS NULL) OR '.
            '(responded_at IS NOT NULL AND responded_by_id IS NOT NULL AND response_text IS NOT NULL AND char_length(response_text) >= 1)'.
            ')'
        );

        // At most one unanswered request may exist per bug.
        DB::statement(
            'CREATE UNIQUE INDEX information_requests_one_open_per_bug ON information_requests (bug_id) '.
            'WHERE responded_at IS NULL'
        );

        $table = 'information_requests';
        Schema::table($table, function (Blueprint $table) {
            $table->index(['bug_id', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('information_requests');
    }
};
