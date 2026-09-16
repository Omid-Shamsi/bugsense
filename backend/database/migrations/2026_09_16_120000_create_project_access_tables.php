<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 20)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignUuid('deactivated_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_key_format CHECK (key ~ '^[A-Z0-9-]{2,20}$')");
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_name_length CHECK (char_length(name) BETWEEN 1 AND 150)');

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignUuid('deactivated_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('membership_id')->constrained('memberships')->restrictOnDelete();
            $table->string('role', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamp('granted_at');
            $table->foreignUuid('granted_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignUuid('deactivated_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['membership_id', 'role']);
        });

        DB::statement("ALTER TABLE membership_roles ADD CONSTRAINT membership_roles_role_check CHECK (role IN ('reporter', 'developer', 'qa', 'admin'))");

        Schema::create('tracking_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('code', 60);
            $table->string('name', 150);
            $table->integer('rank')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignUuid('deactivated_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'kind', 'code']);
        });

        DB::statement("ALTER TABLE tracking_values ADD CONSTRAINT tracking_values_kind_check CHECK (kind IN ('category', 'priority', 'severity', 'tag', 'resolution_label'))");
        DB::statement('ALTER TABLE tracking_values ADD CONSTRAINT tracking_values_name_length CHECK (char_length(name) BETWEEN 1 AND 150)');
        DB::statement(
            'CREATE UNIQUE INDEX tracking_values_active_rank_unique ON tracking_values (project_id, kind, rank) '.
            'WHERE is_active AND rank IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_values');
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('projects');
    }
};
