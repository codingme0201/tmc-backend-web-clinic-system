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
     * Returns the newest entries first, limited by `?limit=` (default 50,
     * maximum 2000); the Audit Logs page paginates them client-side.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ActivityLog::with('author.role');

        // Dashboard administrator feed: filter before the limit so other
        // staff activity cannot push administrator actions out of the feed.
        if ($request->query('role') === 'admin') {
            $query->whereHas('author.role', fn ($role) => $role->where('name', 'admin'));
        }

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

        // Filter for sensitive clinical/medical record activities
        if ($request->boolean('clinical')) {
            $query->whereIn('module', ['Medical Records', 'Consultations', 'Prescriptions', 'Medical Certificates']);
        }

        // Newest first, capped so the trail never loads unbounded
        // (Dashboard widget: 50 by default; Audit Logs page asks for more).
        $limit = min(max((int) $request->query('limit', 50), 1), 2000);

        return ActivityLogResource::collection(
            $query->orderByDesc('id')->limit($limit)->get()
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

        $entry = ActivityLog::record($request->user(), $validated['action'], $validated['module'] ?? null);

        return (new ActivityLogResource($entry->load('author.role')))->response()->setStatusCode(201);
    }
}
