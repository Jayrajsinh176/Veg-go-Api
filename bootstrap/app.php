<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AuthenticateAdmin::class,
            'super_admin.auth' => \App\Http\Middleware\AuthenticateSuperAdmin::class,
            'super_admin.can' => \App\Http\Middleware\EnsureSuperAdminPermission::class,
            'admin.activity' => \App\Http\Middleware\LogAdminActivity::class,
            'change.activity' => \App\Http\Middleware\RecordChangeActivity::class,
            'restaurant.not_suspended' => \App\Http\Middleware\EnsureRestaurantNotSuspended::class,
            'restaurant.approved' => \App\Http\Middleware\EnsureRestaurantApproved::class,
            'customer.not_blocked' => \App\Http\Middleware\EnsureCustomerNotBlocked::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (
            AuthenticationException $e,
            Request $request
        ) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();