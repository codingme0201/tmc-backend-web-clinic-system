<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    /**
     * List the authenticated user's notifications.
     */
    public function myNotifications(Request $request): AnonymousResourceCollection
    {
        return $this->index($request);
    }

    /**
     * List the authenticated user's notifications.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Notification::where('user_id', $request->user()->id);

        if ($type = $request->query('type')) {
            if ($type !== 'All') {
                $query->where('type', $type);
            }
        }

        if ($request->has('unread')) {
            $query->where('is_read', false);
        }

        $notifications = $query->orderByDesc('created_at')->paginate(20);

        return NotificationResource::collection($notifications);
    }

    /**
     * Show a single notification (ownership enforced).
     */
    public function show(Request $request, Notification $notification): NotificationResource|JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403, 'You do not have access to this notification.');
        }

        return new NotificationResource($notification);
    }

    /**
     * Get unread notification count for the authenticated user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Send a notification (admin/staff with notifications.send permission).
     */
    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $notification = Notification::create([
            'user_id' => $validated['user_id'],
            'title' => $validated['title'],
            'message' => $validated['message'],
            'type' => $validated['type'] ?? 'system',
            'category' => $validated['category'] ?? null,
            'source' => $validated['source'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return (new NotificationResource($notification))->response()->setStatusCode(201);
    }

    /**
     * Mark a notification as read (ownership enforced).
     */
    public function markAsRead(Request $request, Notification $notification): NotificationResource|JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403, 'You do not have access to this notification.');
        }

        $notification->update(['is_read' => true]);

        return new NotificationResource($notification);
    }

    /**
     * Mark all of the authenticated user's notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /**
     * Delete a notification (ownership enforced).
     */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403, 'You do not have access to this notification.');
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
