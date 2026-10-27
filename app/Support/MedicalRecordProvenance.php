<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\MedicalRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Attribution uses the existing audit trail, without changing patient data. */
class MedicalRecordProvenance
{
    private const CACHE = 'medical_record_provenance';
    private const LEGACY_MOBILE = [
        'Updated own profile and health information from the mobile app',
        'Registered an account from the mobile app',
    ];

    public static function action(string $kind, int $id, string $source): string
    {
        return "Saved {$kind} #{$id} via {$source}";
    }

    public static function prime(Collection $records, Request $request): void
    {
        if ($records->isEmpty()) return;
        $actions = [];
        $userIds = [];
        foreach ($records as $record) {
            $record->loadMissing('patient.user');
            foreach (['Web', 'Mobile App'] as $source) {
                $actions[] = self::action('medical record', $record->id, $source);
                if ($record->patient) $actions[] = self::action('patient profile', $record->patient->id, $source);
            }
            if ($record->patient?->user) $userIds[] = $record->patient->user->id;
        }
        $logs = ActivityLog::where(function ($query) use ($actions, $userIds) {
            $query->whereIn('action', $actions)
                ->orWhere(fn ($q) => $q->whereIn('user_id', $userIds)->whereIn('action', self::LEGACY_MOBILE));
        })->orderByDesc('id')->get(['id', 'user_id', 'user', 'action', 'created_at']);
        $cache = $request->attributes->get(self::CACHE, []);
        foreach ($records as $record) {
            $patient = $record->patient;
            $matching = $logs->filter(function ($log) use ($record, $patient) {
                foreach (['Web', 'Mobile App'] as $source) {
                    if ($log->action === self::action('medical record', $record->id, $source)
                        || ($patient && $log->action === self::action('patient profile', $patient->id, $source))) return true;
                }

                return $patient?->user && $log->user_id === $patient->user->id && in_array($log->action, self::LEGACY_MOBILE, true);
            });
            $latest = $matching->first();
            $mobile = $matching->first(fn ($log) => str_ends_with($log->action, 'via Mobile App') || in_array($log->action, self::LEGACY_MOBILE, true));
            $updatedAt = collect([$record->updated_at, $patient?->updated_at])->filter()->sortDesc()->first();
            // Do not attribute a later, unaudited edit to an earlier editor.
            if ($latest && $updatedAt && $latest->created_at->lt($updatedAt->copy()->subSecond())) $latest = null;
            $source = $latest ? (str_ends_with($latest->action, 'via Mobile App') || in_array($latest->action, self::LEGACY_MOBILE, true) ? 'Mobile App' : 'Web') : null;
            $cache[$record->id] = [
                'lastUpdatedAt' => $updatedAt?->toIso8601String(),
                'lastUpdatedBy' => $latest ? ['name' => $latest->user, 'source' => $source] : null,
                'studentProvided' => (bool) $mobile,
                'studentUpdatedAt' => $mobile?->created_at?->toIso8601String(),
            ];
        }
        $request->attributes->set(self::CACHE, $cache);
    }

    public static function forRecord(MedicalRecord $record, Request $request): array
    {
        if (! array_key_exists($record->id, $request->attributes->get(self::CACHE, []))) self::prime(collect([$record]), $request);

        return $request->attributes->get(self::CACHE)[$record->id];
    }
}
