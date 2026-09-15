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
     * List the activity/audit log with optional filters.
     *
     * When called without query params (Dashboard), returns the 50 newest.
     * When called with filter params (Audit Logs page), returns paginated results.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ActivityLog::with('user.role');

        // Search — matches action text or user name
        if ($search = $request->query('search')) {
            $q = strtolower($search);
            $query->where(function ($sub) use ($q) {
                $sub->whereRaw('LOWER(action) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(user) LIKE ?', ["%{$q}%"]);
            });
        }

        // Filter by module
        if ($module = $request->query('module')) {
            $query->where('module', $module);
        }

        // Filter by user name
        if ($userName = $request->query('user')) {
            $query->whereRaw('LOWER(user) LIKE ?', ['%' . strtolower($userName) . '%']);
        }

        // Date range filter (uses created_at)
        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // If no filters, apply the default limit for the Dashboard widget
        $hasFilters = $request->hasAny(['search', 'module', 'user', 'from', 'to']);
        if (!$hasFilters) {
            $query->limit(50);
        }

        return ActivityLogResource::collection(
            $query->orderByDesc('id')->get()
        );
    }

    /**
     * Record an activity log entry for the current user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'max:1000'],
            'module' => ['nullable', 'string', 'max:100'],
        ]);

        $entry = ActivityLog::create([
            'user_id' => $request->user()->id,
            'time' => now()->format('h:i A'),
            'user' => $request->user()->name,
            'module' => $validated['module'] ?? null,
            'action' => $validated['action'],
        ]);

        return (new ActivityLogResource($entry->load('user.role')))->response()->setStatusCode(201);
    }
}
