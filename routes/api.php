<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ClinicEventController;
use App\Http\Controllers\ClinicInsightsController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\MedicalCertificateController;
use App\Http\Controllers\MedicalRecordController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffScheduleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Phase 1 — Authentication:
|   POST /api/login   Authenticate, returns a Sanctum bearer token + user.
|   GET  /api/user    Current authenticated user (protected).
|   POST /api/logout  Revoke the current token (protected).
|
| Module 2 — Roles & Permissions (all protected; each route also enforces
| a permission via the `permission:` middleware, so direct API calls from
| users without the right role are rejected with 403).
|
*/

// Public connectivity probe — the frontend calls this to show a clear
// "backend offline" message instead of cryptic proxy errors (e.g. 502).
// No authentication or permissions required; it only verifies the app and
// its database connection are reachable.
Route::get('/health', function () {
    try {
        DB::select('select 1');
        $database = 'up';
    } catch (\Throwable $e) {
        $database = 'down';
    }

    return response()->json([
        'status' => 'ok',
        'service' => 'tmc-carelink-api',
        'database' => $database,
        'time' => now()->toIso8601String(),
    ]);
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Roles & Permissions
    Route::middleware('permission:roles.view')->group(function () {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
        Route::get('/permissions', [PermissionController::class, 'index']);
    });

    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:roles.assign_permissions');

    // Appointments (Module 3)
    Route::middleware('permission:appointments.view')->group(function () {
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
    });

    Route::post('/appointments', [AppointmentController::class, 'store'])->middleware('permission:appointments.create');
    // The target status determines the required permission (approve/reject/update),
    // so the check happens inside the controller rather than a static middleware.
    Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);
    Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('permission:appointments.reschedule');

    // Staff roster (dashboard duty schedule)
    Route::get('/staff', [StaffController::class, 'index'])->middleware('permission:schedules.view');
    Route::patch('/staff/status', [StaffController::class, 'updateStatus'])->middleware('permission:schedules.update');

    // Staff schedules (Module 8 — Doctor/Nurse Schedule)
    Route::get('/staff-schedules/eligible-staff', [StaffScheduleController::class, 'eligibleStaff'])->middleware('permission:schedules.view');
    Route::get('/staff-schedules', [StaffScheduleController::class, 'index'])->middleware('permission:schedules.view');
    Route::get('/staff-schedules/{schedule}', [StaffScheduleController::class, 'show'])->middleware('permission:schedules.view');
    Route::post('/staff-schedules', [StaffScheduleController::class, 'store'])->middleware('permission:schedules.create');
    Route::put('/staff-schedules/{schedule}', [StaffScheduleController::class, 'update'])->middleware('permission:schedules.update');
    Route::patch('/staff-schedules/{schedule}', [StaffScheduleController::class, 'update'])->middleware('permission:schedules.update');
    Route::delete('/staff-schedules/{schedule}', [StaffScheduleController::class, 'destroy'])->middleware('permission:schedules.delete');
    Route::patch('/staff-schedules/{schedule}/availability', [StaffScheduleController::class, 'updateAvailability'])->middleware('permission:schedules.update');

    // Patients registry
    Route::get('/patients', [PatientController::class, 'index'])->middleware('permission:patients.view');
    Route::post('/patients', [PatientController::class, 'store'])->middleware('permission:patients.create');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->middleware('permission:patients.view');
    Route::get('/patients/{patient}/medical-information', [PatientController::class, 'medicalInformation'])->middleware('permission:patients.view');
    Route::get('/patients/{patient}/record-history', [PatientController::class, 'recordHistory'])->middleware('permission:patients.view');
    Route::patch('/patients/{patient}/status', [PatientController::class, 'updateStatus'])->middleware('permission:patients.update');

    // Consultations
    Route::middleware('permission:consultations.view')->group(function () {
        Route::get('/consultations', [ConsultationController::class, 'index']);
        Route::get('/consultations/{consultation}', [ConsultationController::class, 'show']);
    });

    Route::post('/consultations', [ConsultationController::class, 'store'])->middleware('permission:consultations.create');
    Route::post('/consultations/{consultation}/start', [ConsultationController::class, 'start'])->middleware('permission:consultations.create');
    Route::patch('/consultations/{consultation}', [ConsultationController::class, 'update'])->middleware('permission:consultations.update');
    Route::post('/consultations/{consultation}/complete', [ConsultationController::class, 'complete'])->middleware('permission:consultations.update');

    // Medical records (+ nested conditions/allergies)
    Route::get('/medical-records', [MedicalRecordController::class, 'index'])->middleware('permission:medical_records.view');
    Route::middleware('permission:medical_records.update')->group(function () {
        Route::post('/medical-records/{record}/conditions', [MedicalRecordController::class, 'storeCondition']);
        Route::patch('/medical-records/{record}/conditions/{condition}', [MedicalRecordController::class, 'updateCondition']);
        Route::delete('/medical-records/{record}/conditions/{condition}', [MedicalRecordController::class, 'destroyCondition']);
        Route::post('/medical-records/{record}/allergies', [MedicalRecordController::class, 'storeAllergy']);
        Route::patch('/medical-records/{record}/allergies/{allergy}', [MedicalRecordController::class, 'updateAllergy']);
        Route::delete('/medical-records/{record}/allergies/{allergy}', [MedicalRecordController::class, 'destroyAllergy']);
    });

    // Medical certificates
    Route::middleware('permission:medical_certificates.view')->group(function () {
        Route::get('/medical-certificates', [MedicalCertificateController::class, 'index']);
        Route::get('/medical-certificates/{certificate}', [MedicalCertificateController::class, 'show']);
    });

    Route::post('/medical-certificates', [MedicalCertificateController::class, 'store'])->middleware('permission:medical_certificates.create');
    Route::post('/medical-certificates/{certificate}/approve', [MedicalCertificateController::class, 'approve'])->middleware('permission:medical_certificates.approve');
    Route::post('/medical-certificates/{certificate}/reject', [MedicalCertificateController::class, 'reject'])->middleware('permission:medical_certificates.approve');
    Route::post('/medical-certificates/{certificate}/issue', [MedicalCertificateController::class, 'issue'])->middleware('permission:medical_certificates.update');
    Route::patch('/medical-certificates/{certificate}', [MedicalCertificateController::class, 'update'])->middleware('permission:medical_certificates.update');
    Route::delete('/medical-certificates/{certificate}', [MedicalCertificateController::class, 'destroy'])->middleware('permission:medical_certificates.delete');

    // Prescriptions (Module 7)
    Route::middleware('permission:prescriptions.view')->group(function () {
        Route::get('/prescriptions', [PrescriptionController::class, 'index']);
        Route::get('/prescriptions/{prescription}', [PrescriptionController::class, 'show']);
    });

    Route::post('/prescriptions', [PrescriptionController::class, 'store'])->middleware('permission:prescriptions.create');
    Route::patch('/prescriptions/{prescription}', [PrescriptionController::class, 'update'])->middleware('permission:prescriptions.update');

    // Campus health events (legacy — Dashboard widget)
    Route::get('/events', [ClinicEventController::class, 'index'])->middleware('permission:calendar.view');
    Route::post('/events', [ClinicEventController::class, 'store'])->middleware('permission:calendar.create');

    // Clinic Calendar (Module 9)
    Route::get('/calendar', [CalendarController::class, 'index'])->middleware('permission:calendar.view');
    Route::get('/calendar/events', [CalendarController::class, 'indexEvents'])->middleware('permission:calendar.view');
    Route::get('/calendar/events/{event}', [CalendarController::class, 'showEvent'])->middleware('permission:calendar.view');
    Route::post('/calendar/events', [CalendarController::class, 'storeEvent'])->middleware('permission:calendar.create');
    Route::put('/calendar/events/{event}', [CalendarController::class, 'updateEvent'])->middleware('permission:calendar.update');
    Route::delete('/calendar/events/{event}', [CalendarController::class, 'destroyEvent'])->middleware('permission:calendar.delete');

    // Unavailable schedules (block)
    Route::get('/calendar/blocked', [CalendarController::class, 'indexBlocked'])->middleware('permission:calendar.view');
    Route::post('/calendar/blocked', [CalendarController::class, 'blockSchedule'])->middleware('permission:calendar.block');
    Route::delete('/calendar/blocked/{block}', [CalendarController::class, 'destroyBlock'])->middleware('permission:calendar.block');

    // Dashboard insights (activity bars + peak hours)
    Route::get('/insights/activity', [ClinicInsightsController::class, 'activity'])->middleware('permission:dashboard.view');
    Route::get('/insights/peak-hours', [ClinicInsightsController::class, 'peakHours'])->middleware('permission:dashboard.view');

    // User Management
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.update');
    Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->middleware('permission:users.update');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:users.update');

    // Notifications (Module 11)
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('permission:notifications.view');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('permission:notifications.view');
    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->middleware('permission:notifications.view');
    Route::post('/notifications', [NotificationController::class, 'store'])->middleware('permission:notifications.send');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->middleware('permission:notifications.view');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->middleware('permission:notifications.view');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->middleware('permission:notifications.send');

    // Reports (Module 10)
    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/reports/appointments', [ReportController::class, 'appointments']);
        Route::get('/reports/consultations', [ReportController::class, 'consultations']);
        Route::get('/reports/patients', [ReportController::class, 'patients']);
        Route::get('/reports/medical-certificates', [ReportController::class, 'medicalCertificates']);
        Route::get('/reports/prescriptions', [ReportController::class, 'prescriptions']);
        Route::get('/reports/statistics', [ReportController::class, 'statistics']);
    });
    Route::get('/reports/export/{type}', [ReportController::class, 'export'])->middleware('permission:reports.export');

    // Activity / audit log
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:audit_logs.view');
    // Any authenticated user may record their own actions in the log.
    Route::post('/activity-logs', [ActivityLogController::class, 'store']);
});
