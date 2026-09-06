<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends Controller
{
    /**
     * List the activity/audit log, newest first.
     */
    public function index(): AnonymousResourceCollection
    {
        return ActivityLogResource::collection(ActivityLog::orderByDesc('id')->limit(50)->get());
    }

    /**
     * Record an activity log entry for the current user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'max:1000'],
        ]);

        $entry = ActivityLog::create([
            'user_id' => $request->user()->id,
            'time' => now()->format('h:i A'),
            'user' => $request->user()->name,
            'action' => $validated['action'],
        ]);

        return (new ActivityLogResource($entry))->response()->setStatusCode(201);
    }
}
