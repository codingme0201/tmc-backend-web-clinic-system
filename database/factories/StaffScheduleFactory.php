<?php

namespace Database\Factories;

use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffSchedule>
 */
class StaffScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startHour = fake()->numberBetween(7, 15);
        $startMinute = fake()->randomElement([0, 15, 30, 45]);
        $duration = fake()->randomElement([2, 3, 4, 5, 6, 7, 8]);
        $endHour = min($startHour + (int) ceil($duration), 20);
        $endMinute = fake()->randomElement([0, 15, 30, 45]);

        $start = $this->formatTime($startHour, $startMinute);
        $end = $this->formatTime($endHour, $endMinute);

        return [
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'date' => fake()->dateTimeBetween('-14 days', '+30 days')->format('Y-m-d'),
            'start_time' => $start,
            'end_time' => $end,
            'status' => fake()->randomElement(StaffSchedule::STATUSES),
            'notes' => fake()->optional(0.3)->sentence(4),
        ];
    }

    /**
     * Mark the schedule as available.
     */
    public function available(): static
    {
        return $this->state(fn () => ['status' => 'Available']);
    }

    /**
     * Mark the schedule as unavailable.
     */
    public function unavailable(): static
    {
        return $this->state(fn () => ['status' => 'Unavailable']);
    }

    /**
     * Format hours and minutes into "H:MM AM/PM" format.
     */
    private function formatTime(int $hour, int $minute): string
    {
        $period = $hour >= 12 ? 'PM' : 'AM';
        $displayHour = $hour > 12 ? $hour - 12 : ($hour === 0 ? 12 : $hour);
        return sprintf('%d:%02d %s', $displayHour, $minute, $period);
    }
}
