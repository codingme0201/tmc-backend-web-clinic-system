<?php

namespace Database\Factories;

use App\Models\MedicalCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalCertificate>
 */
class MedicalCertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issueDate = fake()->dateTimeBetween('-30 days', 'today');

        return [
            'reference' => 'MC-'.fake()->numberBetween(2024, 2026).'-'.str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'patient' => fake()->name(),
            'patient_id' => fake()->randomElement([null, '24-'.fake()->numerify('######'), '23-'.fake()->numerify('######')]),
            'consultation_id' => null,
            'medical_record_id' => null,
            'issued_by' => fake()->randomElement(['Dr. R. Mendoza', 'Dr. S. Lopez', 'Nurse C. Villanueva']),
            'purpose' => fake()->randomElement([
                'Medical Excuse — Clinic Visit',
                'Fit to Return to Class',
                'Medical Clearance',
                'School Requirement — Physical Exam',
            ]),
            'diagnosis' => fake()->randomElement([
                'Tension Headache due to fatigue',
                'Mild Flu Symptoms',
                'Acid Reflux / GERD Flare-up',
                'Grade 1 Ankle Sprain',
            ]),
            'recommendation' => fake()->sentence(8),
            'issue_date' => $issueDate->format('Y-m-d'),
            'valid_until' => fake()->boolean(40) ? $issueDate->modify('+3 days')->format('Y-m-d') : null,
            'status' => fake()->randomElement(MedicalCertificate::STATUSES),
        ];
    }
}
