<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bug_id')->constrained('bugs')->restrictOnDelete();
            $table->foreignUuid('uploaded_by_id')->constrained('users')->restrictOnDelete();

            // Opaque, generated, never derived from the uploaded filename —
            // this is the only value ever used to locate bytes on disk.
            $table->string('storage_key', 100)->unique();

            // Sanitized presentation-only metadata; never trusted as a
            // filesystem path.
            $table->string('original_name', 255);

            $table->string('declared_content_type', 150);
            $table->string('detected_content_type', 150);
            $table->bigInteger('byte_size');
            $table->char('sha256', 64);

            $table->string('state', 20)->default('ready');
            $table->timestamp('removed_at')->nullable();
            $table->foreignUuid('removed_by_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamp('created_at');

            $table->index('bug_id');
        });

        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_state_check '.
            "CHECK (state IN ('ready', 'removed'))");
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_byte_size_positive '.
            'CHECK (byte_size > 0)');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_sha256_length '.
            'CHECK (char_length(sha256) = 64)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
