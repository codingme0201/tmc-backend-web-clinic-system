<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsurePatientUser;
use App\Http\Middleware\EnsureStaffUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This is an API-first application: the framework default guest
        // redirect targets a named 'login' web route, which does not exist
        // here and would throw for unauthenticated requests. Point guests at
        // the SPA login path instead; JSON API clients (Accept:
        // application/json) still receive 401 responses.
        $middleware->redirectGuestsTo('/login');

        // Module 2 — role/permission checks, e.g. `permission:roles.create`.
        $middleware->alias([
            'permission' => CheckPermission::class,
            'patient.user' => EnsurePatientUser::class,
            'staff.user' => EnsureStaffUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
