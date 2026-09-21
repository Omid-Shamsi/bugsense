<?php

namespace Database\Factories;

use App\Models\Bug;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bug>
 */
class BugFactory extends Factory
{
    protected $model = Bug::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'reporter_id' => User::factory(),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
        ];
    }

    /**
     * public_number is assigned by a database sequence on insert (see the
     * migration), so a freshly created in-memory instance must be refreshed
     * to read it back — otherwise it reads as null, like any other
     * DB-computed default Eloquent does not backfill automatically.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Bug $bug) {
            $bug->refresh();
        });
    }
}
