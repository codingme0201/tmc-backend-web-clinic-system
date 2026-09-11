<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'message' => fake()->paragraph(1),
            'type' => fake()->randomElement(['system', 'appointment', 'patient', 'clinic']),
            'category' => fake()->optional()->word(),
            'source' => fake()->optional()->word(),
            'is_read' => fake()->boolean(30),
            'metadata' => null,
        ];
    }

    public function unread(): static
    {
        return $this->state(fn () => ['is_read' => false]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['is_read' => true]);
    }

    public function appointment(): static
    {
        return $this->state(fn () => [
            'type' => 'appointment',
            'category' => 'appointment',
        ]);
    }

    public function patient(): static
    {
        return $this->state(fn () => [
            'type' => 'patient',
            'category' => 'patient',
        ]);
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'type' => 'system',
            'category' => 'system',
        ]);
    }
}
