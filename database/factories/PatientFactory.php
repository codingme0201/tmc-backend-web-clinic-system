<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['Student', 'Faculty', 'Staff']);
        $name = fake()->name();

        return [
            'patient_id' => match ($type) {
                'Student' => (string) fake()->numberBetween(2020, 2024).'-'.str_pad((string) fake()->unique()->numberBetween(100, 999), 4, '0', STR_PAD_LEFT),
                default => 'EMP-'.str_pad((string) fake()->unique()->numberBetween(100, 999), 3, '0', STR_PAD_LEFT),
            },
            'name' => $name,
            'type' => $type,
            'course_dept' => match ($type) {
                'Student' => fake()->randomElement([
                    'BS Computer Science', 'BS Information Technology',
                    'BS Business Administration', 'BS Hospitality Management',
                    'BEED Elementary Education', 'BS Engineering',
                    'BS Nursing', 'BS Accountancy',
                ]),
                'Faculty' => fake()->randomElement([
                    'College of Engineering', 'College of Education',
                    'College of Business', 'College of Computer Studies',
                ]),
                default => fake()->randomElement([
                    'Registrar Office', 'Finance Office',
                    'Student Affairs', 'IT Department',
                ]),
            },
            'contact' => '09'.fake()->numerify('##-###-####'),
            'emergency_contact' => fake()->name().' ('.fake()->randomElement(['Mother', 'Father', 'Spouse', 'Sibling']).') - 09'.fake()->numerify('##-###-####'),
            'allergies' => fake()->randomElement(['None', 'Peanuts', 'Penicillin', 'Seafood', 'Sulfa drugs', 'Dust Mites', 'Aspirin']),
            'history' => fake()->randomElement(['None', 'Mild Asthma', 'Migraines', 'GERD', 'Allergic Rhinitis', 'Hypertension', 'Eczema']),
            'status' => fake()->randomElement(['Active', 'Active', 'Active', 'Inactive']),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'Inactive']);
    }
}
