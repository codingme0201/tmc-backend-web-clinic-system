<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Reject the request unless the authenticated user's role grants the
     * given permission (e.g. `permission:roles.create`).
     *
     * This is the backend security boundary — clients hiding UI elements is
     * not sufficient on its own.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        // Front Desk profile changes go through /me/staff-profile only, even
        // if an older role assignment still contains account-management grants.
        if ($request->user()?->role?->name === 'front_desk'
            && in_array($permission, ['users.create', 'users.update', 'users.delete'], true)) {
            abort(403, 'Front desk staff can edit only their own profile.');
        }

        if (! $request->user() || ! $request->user()->hasPermission($permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
