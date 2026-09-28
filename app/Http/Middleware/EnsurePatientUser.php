<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePatientUser
{
    /**
     * Restrict the mobile self-service routes (`/api/me/*`) to student/patient
     * accounts. Clinic staff manage records through the permission-guarded
     * staff endpoints instead.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->isPatientUser()) {
            abort(403, 'This endpoint is only available to student accounts.');
        }

        return $next($request);
    }
}
