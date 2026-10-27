<?php

namespace App\Http\Controllers;

use App\Models\StaffSchedule;
use App\Models\User;
use App\Models\WeeklyStaffSchedule;
use App\Services\WeeklyScheduleService;
use App\Support\ClinicSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WeeklyStaffScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => WeeklyStaffSchedule::query()
            ->when($request->user()->isClinician(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderBy('weekday')->get(['user_id', 'weekday', 'start_time', 'end_time'])]);
    }

    public function store(Request $request, WeeklyScheduleService $service): JsonResponse
    {
        abort_unless(StaffSchedule::canManageFor($request->user(), (int) $request->input('user_id')), 403);
        $values = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'days' => ['required', 'array', 'min:1', 'max:5'],
            'days.*.weekday' => ['required', 'integer', 'between:1,5', 'distinct'],
            'days.*.start_time' => ['required', Rule::in(ClinicSchedule::shiftTimes())],
            'days.*.end_time' => ['required', Rule::in(ClinicSchedule::shiftTimes())],
        ]);
        $user = User::with(['role', 'staffProfile'])->findOrFail($values['user_id']);
        abort_unless($user->isClinician() && $user->status === 'active' && ! $user->isCredentialRejected(), 422, 'Please select an active doctor or nurse with eligible credentials.');
        foreach ($values['days'] as $index => $day) {
            if (ClinicSchedule::toMinutes($day['end_time']) <= ClinicSchedule::toMinutes($day['start_time'])) {
                throw ValidationException::withMessages(["days.{$index}.end_time" => 'End time must be after start time.']);
            }
        }
        $service->save($user->id, $values['days']);

        return response()->json(['message' => 'Weekly schedule saved. Date-specific changes have been preserved.']);
    }
}
