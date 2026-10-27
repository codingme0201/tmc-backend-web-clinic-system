<?php

namespace App\Http\Controllers;

use App\Support\AppointmentCalendar;
use App\Services\AppointmentScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentCalendarController extends Controller
{
    public function index(Request $request, AppointmentCalendar $calendar, AppointmentScheduler $scheduler): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:1900,2100'],
            'month' => ['required_with:staff_id', 'integer', 'between:1,12'],
            'staff_id' => ['nullable', 'integer'],
        ]);
        $data = $calendar->year((int) $validated['year']);
        if (! empty($validated['staff_id'])) {
            $staff = $scheduler->clinicians()->find($validated['staff_id']);
            abort_unless($staff, 422, 'Doctor not available');
            $data['days'] = (object) $scheduler->monthAvailability($staff, (int) $validated['year'], (int) $validated['month']);
        }

        return response()->json(['data' => $data]);
    }
}
