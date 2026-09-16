<?php

namespace Database\Factories;

use App\Enums\TrackingValueKind;
use App\Models\Project;
use App\Models\TrackingValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackingValue>
 */
class TrackingValueFactory extends Factory
{
    protected $model = TrackingValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'kind' => TrackingValueKind::Category,
            'code' => strtolower(fake()->unique()->lexify('cat_???')),
            'name' => fake()->word(),
        ];
    }
}
