<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'Admin authentication token is required.',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json([
                'message' => 'Invalid or expired admin token.',
            ], 401);
        }

        $admin = $accessToken->tokenable;

        if (!$admin instanceof Admin) {
            return response()->json([
                'message' => 'Unauthorized admin access.',
            ], 403);
        }

        if ($admin->status !== 'active') {
            return response()->json([
                'message' => 'Your admin account is inactive.',
            ], 403);
        }

        // Attach the Sanctum token to the Admin model
        $admin->withAccessToken($accessToken);

        // Tell Laravel that this request is authenticated as this Admin
        $request->setUserResolver(function () use ($admin) {
            return $admin;
        });

        return $next($request);
    }
}