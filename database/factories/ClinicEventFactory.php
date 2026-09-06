<?php

namespace Database\Factories;

use App\Models\ClinicEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicEvent>
 */
class ClinicEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-14 days', '+30 days');
        $allDay = fake()->boolean(60);
        $type = fake()->randomElement(ClinicEvent::TYPES);

        $startTime = null;
        $endTime = null;
        if (!$allDay) {
            $startHour = fake()->numberBetween(8, 14);
            $startTime = $this->formatTime($startHour, fake()->randomElement([0, 30]));
            $endTime = $this->formatTime($startHour + fake()->numberBetween(1, 4), fake()->randomElement([0, 30]));
        }

        return [
            'date' => $startDate->format('M d, Y'),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $startDate->format('Y-m-d'),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'all_day' => $allDay,
            'title' => fake()->sentence(4),
            'description' => fake()->optional(0.6)->paragraph(1),
            'type' => $type,
            'status' => fake()->randomElement(ClinicEvent::STATUSES),
            'created_by' => User::inRandomOrder()->first()?->id ?? User::factory(),
        ];
    }

    /**
     * Mark the event as all-day.
     */
    public function allDay(): static
    {
        return $this->state(fn () => [
            'all_day' => true,
            'start_time' => null,
            'end_time' => null,
        ]);
    }

    /**
     * Mark the event as a holiday.
     */
    public function holiday(): static
    {
        return $this->state(fn () => [
            'type' => 'Holiday',
            'all_day' => true,
            'start_time' => null,
            'end_time' => null,
        ]);
    }

    private function formatTime(int $hour, int $minute): string
    {
        $period = $hour >= 12 ? 'PM' : 'AM';
        $displayHour = $hour > 12 ? $hour - 12 : ($hour === 0 ? 12 : $hour);
        return sprintf('%d:%02d %s', $displayHour, $minute, $period);
    }
}
