<?php

namespace App\Http\Middleware;

use App\Models\Appointment;
use App\Services\AppointmentAutoStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefreshAppointmentStatuses
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(AppointmentAutoStatus::class)->refresh() > 0) {
            // Route binding may already have loaded an appointment before this check.
            foreach ($request->route()->parameters() as $parameter) {
                if ($parameter instanceof Appointment) $parameter->refresh();
            }
        }

        return $next($request);
    }
}
