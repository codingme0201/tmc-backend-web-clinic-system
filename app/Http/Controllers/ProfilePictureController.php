<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\SyncState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ProfilePictureController extends Controller
{
    private function ensureCanEdit(User $user): void
    {
        abort_unless(in_array($user->role?->name, ['doctor', 'front_desk'], true), 403, 'You cannot edit this profile picture.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCanEdit($request->user());
        $request->validate([
            'picture' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'user_id' => ['prohibited'], 'userId' => ['prohibited'], 'profile_picture_path' => ['prohibited'],
        ]);
        $path = $request->file('picture')->store('profile-pictures', 'local');
        abort_unless($path, 500, 'The profile picture could not be saved.');
        try {
            [$user, $oldPath] = DB::transaction(function () use ($request, $path) {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $this->ensureCanEdit($user);
                $oldPath = $user->profile_picture_path;
                $user->forceFill(['profile_picture_path' => $path])->save();
                ActivityLog::record($user, 'Updated own profile picture', 'Medical Staff');
                return [$user, $oldPath];
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($oldPath) Storage::disk('local')->delete($oldPath);
        SyncState::bump('users', 'activity_logs');

        return response()->json(['profilePictureUrl' => $user->profilePictureUrl()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->ensureCanEdit($request->user());
        $request->validate(['user_id' => ['prohibited'], 'userId' => ['prohibited']]);
        $oldPath = DB::transaction(function () use ($request) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->ensureCanEdit($user);
            $oldPath = $user->profile_picture_path;
            if ($oldPath) {
                $user->forceFill(['profile_picture_path' => null])->save();
                ActivityLog::record($user, 'Removed own profile picture', 'Medical Staff');
            }
            return $oldPath;
        });
        if ($oldPath) Storage::disk('local')->delete($oldPath);
        if ($oldPath) SyncState::bump('users', 'activity_logs');

        return response()->json(['profilePictureUrl' => null]);
    }

    public function show(Request $request, User $user): BinaryFileResponse
    {
        $path = $user->profile_picture_path;
        abort_unless($path && str_starts_with($path, 'profile-pictures/') && Storage::disk('local')->exists($path), 404);
        abort_unless(hash_equals(hash('sha256', $path), (string) $request->query('v')), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => Storage::disk('local')->mimeType($path),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ])->setPrivate()->setMaxAge(300);
    }
}
