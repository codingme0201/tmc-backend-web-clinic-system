<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffUser
{
    /**
     * Restrict a route to clinic staff (any non-student/patient role).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || $request->user()->isPatientUser()) {
            abort(403, 'This endpoint is only available to clinic staff.');
        }

        return $next($request);
    }
}
