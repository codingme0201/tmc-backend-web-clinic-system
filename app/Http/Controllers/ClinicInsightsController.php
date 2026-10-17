<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\MedicalCertificate;
use App\Models\Prescription;
use App\Support\ClinicSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard insights computed from the clinic's live records. Totals cover
 * every record (so the charts are never empty while records exist); each
 * row also carries the count for the last 30 days (today included).
 */
class ClinicInsightsController extends Controller
{
    public const RECENT_DAYS = 30;

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
     * Clinic activity bars: { label, count, recentCount, percent } per record type.
     */
    public function activity(): JsonResponse
    {
        $sources = [
            'Appointments' => [fn () => Appointment::whereNotIn('status', ['Cancelled', 'Rejected']), 'date'],
            'Consultations' => [fn () => Consultation::query(), 'date'],
            'Prescriptions' => [fn () => Prescription::query(), 'prescription_date'],
            'Medical Certificates' => [fn () => MedicalCertificate::query(), 'issue_date'],
        ];

        $rows = [];
        foreach ($sources as $label => [$query, $dateColumn]) {
            $rows[$label] = [
                'count' => $query()->count(),
                'recentCount' => $this->recent($query(), $dateColumn)->count(),
            ];
        }

        return response()->json($this->withPercent($rows));
    }

    /**
     * Peak visit hours: { label, count, recentCount, percent } per hour band.
     * Visits are booked appointments (not cancelled or rejected) plus walk-in
     * consultations that were not started from an appointment.
     */
    public function peakHours(): JsonResponse
    {
        $visits = Appointment::whereNotIn('status', ['Cancelled', 'Rejected'])->get(['time', 'date'])->toBase()
            ->concat(Consultation::whereNull('appointment_id')->get(['time', 'date'])->all())
            ->map(fn ($visit) => [
                'minutes' => ClinicSchedule::toMinutes($visit->time),
                'recent' => $visit->date?->format('Y-m-d') >= $this->recentFrom(),
            ])
            ->filter(fn ($visit) => $visit['minutes'] !== null);

        $rows = [];
        foreach (self::HOUR_BANDS as [$start, $end]) {
            $startMinutes = ClinicSchedule::toMinutes($start);
            $endMinutes = ClinicSchedule::toMinutes($end);
            $inBand = $visits->filter(fn ($v) => $v['minutes'] >= $startMinutes
                && ($v['minutes'] < $endMinutes || ($end === ClinicSchedule::WORK_END && $v['minutes'] === $endMinutes)));

            $rows["{$start} - {$end}"] = [
                'count' => $inBand->count(),
                'recentCount' => $inBand->where('recent', true)->count(),
            ];
        }

        return response()->json($this->withPercent($rows));
    }

    private function recentFrom(): string
    {
        return now()->subDays(self::RECENT_DAYS - 1)->toDateString();
    }

    private function recent(Builder $query, string $dateColumn): Builder
    {
        return $query->whereBetween($dateColumn, [$this->recentFrom(), now()->toDateString()]);
    }

    /**
     * @param  array<string, array{count: int, recentCount: int}>  $rows
     * @return array<int, array{label: string, count: int, recentCount: int, percent: int}>
     */
    private function withPercent(array $rows): array
    {
        $max = max(1, ...array_column($rows, 'count'));

        return collect($rows)
            ->map(fn (array $row, string $label) => [
                'label' => $label,
                ...$row,
                'percent' => (int) round($row['count'] / $max * 100),
            ])
            ->values()
            ->all();
    }
}
