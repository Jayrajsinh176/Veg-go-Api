<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'Super admin authentication token is required.',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json([
                'message' => 'Invalid or expired super admin token.',
            ], 401);
        }

        $admin = $accessToken->tokenable;

        // Super admins, or role-based admins holding a super admin panel token
        $allowed = $admin instanceof Admin && (
            $admin->isSuperAdmin()
            || ($admin->canAccessSuperAdminPanel() && $accessToken->can('super-admin'))
        );

        if (!$allowed) {
            return response()->json([
                'message' => 'Unauthorized super admin access.',
            ], 403);
        }

        if ($admin->status !== 'active') {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        // Attach the Sanctum token to the Admin model
        $admin->withAccessToken($accessToken);

        // Tell Laravel that this request is authenticated as this Super Admin
        $request->setUserResolver(function () use ($admin) {
            return $admin;
        });

        return $next($request);
    }
}
