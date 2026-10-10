<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit trail for the student mobile app: every successful change a student
 * makes through `/api/me/*` is recorded (the web admin logs its own actions
 * from the client with more descriptive messages).
 */
class RecordStudentActivity
{
    /**
     * [method, uri pattern (after api/me/), module, action]
     */
    private const ACTIONS = [
        ['PUT', 'profile', 'Patients', 'Updated own profile and health information from the mobile app'],
        ['POST', 'appointments', 'Appointments', 'Requested an appointment from the mobile app'],
        ['POST', 'appointments/*/reschedule', 'Appointments', 'Rescheduled an appointment from the mobile app'],
        ['POST', 'appointments/*/cancel', 'Appointments', 'Cancelled an appointment from the mobile app'],
        ['POST', 'medical-certificates', 'Medical Certificates', 'Requested a medical certificate from the mobile app'],
        ['POST', 'support', 'System', 'Sent a support message from the mobile app'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || ! $request->user() || $response->getStatusCode() >= 400) {
            return $response;
        }

        $path = preg_replace('#^api/me/#', '', $request->path());
        foreach (self::ACTIONS as [$method, $pattern, $module, $action]) {
            if ($request->method() === $method && fnmatch($pattern, $path, FNM_PATHNAME)) {
                $reference = $this->reference($response);
                ActivityLog::record($request->user(), $reference ? "{$action} ({$reference})" : $action, $module);
                break;
            }
        }

        return $response;
    }

    private function reference(Response $response): ?string
    {
        $data = json_decode((string) $response->getContent(), true);
        $data = $data['data'] ?? $data;

        return is_array($data) ? ($data['reference'] ?? $data['certificateNumber'] ?? null) : null;
    }
}
