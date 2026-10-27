<?php

namespace App\Http\Controllers;

use App\Http\Resources\StaffScheduleResource;
use App\Models\Appointment;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Models\WeeklyStaffSchedule;
use App\Services\WeeklyScheduleService;
use App\Services\AppointmentScheduler;
use App\Support\AppointmentCalendar;
use App\Support\ClinicSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffScheduleDirectoryController extends Controller
{
    /** One database user per row, regardless of the number of dated shifts. */
    public function index(Request $request, WeeklyScheduleService $weekly, AppointmentScheduler $scheduler): JsonResponse
    {
        $today = now()->toDateString();
        $weekly->ensureRange($today, now()->addDays(35)->toDateString(), $request->user()->isClinician() ? $request->user()->id : null);
        $staff = User::with(['role', 'staffProfile', 'staffSchedules' => fn ($q) => $q->whereDate('date', $today)])
            ->whereHas('role', fn ($q) => $q->whereIn('name', User::CLINICIAN_ROLES))
            ->where(fn ($q) => $q->whereHas('staffSchedules')->orWhereHas('weeklyStaffSchedules'))
            ->when($request->user()->isClinician(), fn ($q) => $q->whereKey($request->user()->id))
            ->withMin(['staffSchedules as next_date' => fn ($q) => $q->whereDate('date', '>=', $today)], 'date')
            ->orderBy('name')->orderBy('id')->get();

        return response()->json(['data' => $staff->map(fn ($user) => [
            'userId' => $user->id,
            'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role?->name],
            'department' => $user->clinicalDepartment(),
            'specialization' => $user->staffProfile?->specialization,
            'email' => $user->email,
            'status' => $scheduler->currentStaffStatus($user, $user->staffSchedules),
            'nextDate' => $user->next_date,
        ])->values()]);
    }

    /** Staff-only weekly detail, including assignments even after hours are changed. */
    public function show(Request $request, User $user, WeeklyScheduleService $weekly, AppointmentCalendar $calendar): JsonResponse
    {
        abort_if($request->user()->isClinician() && $request->user()->id !== $user->id, 403, 'You can only access your own schedules.');
        $user->load(['role', 'staffProfile']);
        abort_unless($user->isClinician(), 404);
        $values = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $monday = CarbonImmutable::parse($values['date'] ?? now()->toDateString())->startOfWeek(CarbonInterface::MONDAY);
        $friday = $monday->addDays(4);
        $weekly->ensureRange($monday->toDateString(), $friday->toDateString(), $user->id);
        $shifts = StaffSchedule::where('user_id', $user->id)
            ->whereBetween('date', [$monday->toDateString(), $friday->toDateString()])->get()
            ->sortBy(fn ($s) => [$s->date->toDateString(), ClinicSchedule::toMinutes($s->start_time), $s->id])
            ->each(fn ($s) => $s->setRelation('user', $user))
            ->groupBy(fn ($s) => $s->date->toDateString());
        $regular = WeeklyStaffSchedule::where('user_id', $user->id)->get()->keyBy('weekday');
        $appointments = Appointment::sortFifo(Appointment::where('staff_id', $user->id)
            ->whereBetween('date', [$monday->toDateString(), $friday->toDateString()])
            ->whereIn('status', [...Appointment::ACTIVE_STATUSES, 'Completed'])->get());
        $days = [];
        for ($offset = 0; $offset < 5; $offset++) {
            $day = $monday->addDays($offset);
            $rule = $regular->get($day->dayOfWeekIso);
            $days[] = [
                'label' => $day->format('l'), 'date' => $day->toDateString(),
                'closedReason' => $calendar->reason($day->toDateString()),
                'regularHours' => $rule && $rule->effective_from->toDateString() <= $day->toDateString()
                    ? ['startTime' => $rule->start_time, 'endTime' => $rule->end_time] : null,
                'schedules' => $shifts->get($day->toDateString(), collect())->values()
                    ->map(fn ($s) => (new StaffScheduleResource($s))->resolve($request))->all(),
            ];
        }

        return response()->json(['data' => [
            'userId' => $user->id,
            'name' => $user->name,
            'weekStart' => $monday->toDateString(), 'weekEnd' => $friday->toDateString(),
            'days' => $days,
            'assignedPatients' => $appointments->map(fn ($a) => [
                'id' => $a->id, 'reference' => $a->reference, 'patient' => $a->patient,
                'date' => $a->date->toDateString(), 'time' => ClinicSchedule::normalize($a->time),
                'visitType' => $a->visit_type ?? Appointment::VISIT_NEW, 'status' => $a->status,
            ])->values(),
        ]]);
    }
}
