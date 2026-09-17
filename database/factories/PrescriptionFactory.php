<?php

namespace Database\Factories;

use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Uses the clinic's recurring patient roster (same names/registry ids the
     * seeders use) so factory records stay consistent with the seeded demo
     * data instead of producing disconnected fake names.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $patient = fake()->randomElement([
            ['name' => 'Angela Reyes', 'id' => '24-021128'],
            ['name' => 'Mark Dela Cruz', 'id' => '22-010941'],
            ['name' => 'Joanna Lim', 'id' => '21-011122'],
            ['name' => 'Susan Clave', 'id' => 'EMP-119'],
            ['name' => 'John Paul Santos', 'id' => '23-010881'],
            ['name' => 'Patricia Mae Garcia', 'id' => '24-010012'],
        ]);
        $date = fake()->dateTimeBetween('-90 days', 'today');

        return [
            'reference' => 'RX-'.date('Y', $date->getTimestamp()).'-'.str_pad(
                (string) fake()->unique()->numberBetween(1, 999),
                3,
                '0',
                STR_PAD_LEFT,
            ),
            'patient' => $patient['name'],
            'patient_id' => $patient['id'],
            'consultation_id' => null,
            'medical_record_id' => null,
            'prescribed_by' => fake()->randomElement(['Dr. R. Mendoza', 'Dr. S. Lopez', 'Nurse C. Villanueva', 'Nurse J. Santos']),
            'prescription_date' => $date->format('Y-m-d'),
        ];
    }
}
