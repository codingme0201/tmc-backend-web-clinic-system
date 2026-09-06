<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UnavailableSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnavailableSchedule>
 */
class UnavailableScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('+7 days', '+30 days');
        $allDay = fake()->boolean(70);

        $startTime = null;
        $endTime = null;
        if (!$allDay) {
            $startHour = fake()->numberBetween(8, 14);
            $startTime = $this->formatTime($startHour, fake()->randomElement([0, 30]));
            $endTime = $this->formatTime($startHour + fake()->numberBetween(1, 4), fake()->randomElement([0, 30]));
        }

        return [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $startDate->format('Y-m-d'),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'all_day' => $allDay,
            'reason' => fake()->optional(0.7)->sentence(4),
            'created_by' => User::inRandomOrder()->first()?->id ?? User::factory(),
        ];
    }

    private function formatTime(int $hour, int $minute): string
    {
        $period = $hour >= 12 ? 'PM' : 'AM';
        $displayHour = $hour > 12 ? $hour - 12 : ($hour === 0 ? 12 : $hour);
        return sprintf('%d:%02d %s', $displayHour, $minute, $period);
    }
}
