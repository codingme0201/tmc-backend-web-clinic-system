<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\MedicalCertificate;
use App\Models\Prescription;
use App\Support\ClinicSchedule;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard insights computed from the clinic's live records over the last
 * 30 days (today included).
 */
class ClinicInsightsController extends Controller
{
    public const WINDOW_DAYS = 30;

    /**
     * Hour bands within clinic hours (8:00 AM – 5:00 PM).
     */
    private const HOUR_BANDS = [
        ['08:00 AM', '10:00 AM'],
        ['10:00 AM', '12:00 PM'],
        ['12:00 PM', '02:00 PM'],
        ['02:00 PM', '04:00 PM'],
        ['04:00 PM', '05:00 PM'],
    ];

    /**
     * Clinic activity bars: { label, count, percent } per record type.
     */
    public function activity(): JsonResponse
    {
        [$from, $to] = $this->window();

        $counts = [
            'Appointments' => Appointment::whereBetween('date', [$from, $to])
                ->whereNotIn('status', ['Cancelled', 'Rejected'])->count(),
            'Consultations' => Consultation::whereBetween('date', [$from, $to])->count(),
            'Prescriptions' => Prescription::whereBetween('prescription_date', [$from, $to])->count(),
            'Medical Certificates' => MedicalCertificate::whereBetween('issue_date', [$from, $to])->count(),
        ];

        return response()->json($this->withPercent($counts));
    }

    /**
     * Peak visit hours: { label, count, percent } per hour band. Visits are
     * booked appointments (not cancelled or rejected) plus walk-in
     * consultations that were not started from an appointment.
     */
    public function peakHours(): JsonResponse
    {
        [$from, $to] = $this->window();

        $times = Appointment::whereBetween('date', [$from, $to])
            ->whereNotIn('status', ['Cancelled', 'Rejected'])
            ->pluck('time')
            ->merge(Consultation::whereBetween('date', [$from, $to])->whereNull('appointment_id')->pluck('time'))
            ->map(fn ($time) => ClinicSchedule::toMinutes($time))
            ->filter(fn ($minutes) => $minutes !== null);

        $counts = [];
        foreach (self::HOUR_BANDS as [$start, $end]) {
            $startMinutes = ClinicSchedule::toMinutes($start);
            $endMinutes = ClinicSchedule::toMinutes($end);
            $counts["{$start} - {$end}"] = $times
                ->filter(fn ($m) => $m >= $startMinutes && ($m < $endMinutes || ($end === ClinicSchedule::WORK_END && $m === $endMinutes)))
                ->count();
        }

        return response()->json($this->withPercent($counts));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function window(): array
    {
        return [now()->subDays(self::WINDOW_DAYS - 1)->toDateString(), now()->toDateString()];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, array{label: string, count: int, percent: int}>
     */
    private function withPercent(array $counts): array
    {
        $max = max(1, ...array_values($counts));

        return collect($counts)
            ->map(fn (int $count, string $label) => [
                'label' => $label,
                'count' => $count,
                'percent' => (int) round($count / $max * 100),
            ])
            ->values()
            ->all();
    }
}
