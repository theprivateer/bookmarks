<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bookmark>
 */
class BookmarkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->url();

        return [
            'user_id' => User::factory(),
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'status' => 'pending',
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processed',
            'og_image_url' => fake()->imageUrl(),
            'favicon_url' => 'https://www.google.com/s2/favicons?domain='.parse_url($attributes['url'] ?? fake()->url(), PHP_URL_HOST).'&sz=64',
            'extracted_text' => fake()->paragraphs(3, true),
            'ai_summary' => fake()->paragraph(),
            'embedding' => array_fill(0, 1536, 0.1),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }
}
