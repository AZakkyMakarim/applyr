<?php

namespace Database\Factories;

use App\Enums\JobTypeFilter;
use App\Enums\PostDateRange;
use App\Enums\WorkArrangementFilter;
use App\Models\SearchProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchProfile>
 */
class SearchProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'is_active' => true,
            'keyword' => [fake()->word(), fake()->word()],
            'location' => fake()->city(),
            'country_code' => 'ID',
            'min_experience_years' => null,
            'max_experience_years' => null,
            'post_date_range' => PostDateRange::PastWeek,
            'work_arrangement' => WorkArrangementFilter::Any,
            'job_type' => JobTypeFilter::Any,
        ];
    }

    /**
     * Indicate that the SearchProfile is paused.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
