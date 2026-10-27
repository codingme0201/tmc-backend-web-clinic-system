<?php

namespace Database\Factories;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Active workflow fixtures should default to upcoming appointments.
        $date = fake()->dateTimeBetween('+1 day', '+21 days');

        return [
            'reference' => 'APT-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'patient' => fake()->name(),
            'patient_id' => fake()->randomElement([null, '2023-'.fake()->numberBetween(100, 999)]),
            'type' => fake()->randomElement(Appointment::TYPES),
            'reason' => fake()->sentence(6),
            'date' => $date->format('Y-m-d'),
            'time' => fake()->randomElement(Appointment::TIME_SLOTS),
            'staff' => fake()->randomElement(['Dr. R. Mendoza', 'Dr. S. Lopez', 'Nurse C. Villanueva', 'Nurse J. Santos', null]),
            'status' => fake()->randomElement(['Pending', 'Under Review', 'Approved', 'Rescheduled', 'Rejected', 'Cancelled', 'Completed']),
            'notes' => null,
            'requested_on' => $date->modify('-2 days')->format('Y-m-d'),
        ];
    }
}
