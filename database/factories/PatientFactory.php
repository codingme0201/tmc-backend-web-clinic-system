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
        $type = 'Student';
        $name = fake()->name();

        return [
            'patient_id' => fake()->numberBetween(20, 25).'-'.str_pad((string) fake()->unique()->numberBetween(10000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => $name,
            'type' => $type,
            'course_dept' => fake()->randomElement([
                'BS Computer Science', 'BS Information Technology',
                'BS Business Administration', 'BS Hospitality Management',
                'BEED Elementary Education', 'BS Engineering',
                'BS Nursing', 'BS Accountancy',
            ]),
            'contact' => '+63 9'.fake()->numerify('## ### ####'),
            'emergency_contact' => fake()->name().' ('.fake()->randomElement(['Mother', 'Father', 'Spouse', 'Sibling']).') - +63 9'.fake()->numerify('## ### ####'),
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
