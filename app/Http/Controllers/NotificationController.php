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
     * Users a notification can be sent to: every active account except the
     * sender, grouped client-side by role. Available to anyone who can send
     * notifications (not only user managers).
     */
    public function recipients(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::with('role')
            ->where('status', 'active')
            ->where('id', '!=', $request->user()->id)
            ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('patient_id', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role?->name,
            'patientId' => $u->patient_id,
        ])->values()]);
    }

    /**
     * Notifications the signed-in user has sent, newest first, with recipients.
     */
    public function sent(Request $request): AnonymousResourceCollection
    {
        $notifications = Notification::with(['user.role', 'sender'])
            ->where('sender_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return NotificationResource::collection($notifications);
    }

    /**
     * Send a notification to one selected user (staff with notifications.send).
     */
    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $notification = Notification::create([
            'user_id' => $validated['user_id'],
            'sender_id' => $request->user()->id,
            'title' => $validated['title'],
            'message' => $validated['message'],
            'type' => $validated['type'] ?? 'system',
            'category' => $validated['category'] ?? null,
            'source' => $validated['source'] ?? $request->user()->name,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return (new NotificationResource($notification->load(['user.role', 'sender'])))->response()->setStatusCode(201);
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
        // Recipients delete from their inbox; senders can withdraw what they sent.
        if ($notification->user_id !== $request->user()->id && $notification->sender_id !== $request->user()->id) {
            abort(403, 'You do not have access to this notification.');
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
