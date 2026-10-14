<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the Roles & Permissions matrix for the signed-in admin.
 *
 *   super_admin.can:restaurants              -> action taken from the HTTP method
 *   super_admin.can:restaurants,Approve      -> explicit action
 *   super_admin.can:restaurants|orders,View  -> any one of the modules is enough
 *   super_admin.can:owner                    -> real super admins only
 *
 * Must run after super_admin.auth.
 */
class EnsureSuperAdminPermission
{
    private const METHOD_ACTIONS = [
        'GET' => 'View',
        'HEAD' => 'View',
        'POST' => 'Create',
        'PUT' => 'Edit',
        'PATCH' => 'Edit',
        'DELETE' => 'Delete',
    ];

    public function handle(Request $request, Closure $next, string $modules, ?string $action = null): Response
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($admin->isSuperAdmin() || !$this->isMostSpecificCheck($request, $modules, $action)) {
            return $next($request);
        }

        if ($modules !== 'owner') {
            $action ??= self::METHOD_ACTIONS[$request->method()] ?? 'Edit';

            foreach (explode('|', $modules) as $module) {
                if ($admin->hasPermission($module, $action)) {
                    return $next($request);
                }
            }
        }

        return response()->json([
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }

    /**
     * A route-level check overrides its group's check, so only the last
     * super_admin.can entry on the route is enforced.
     */
    private function isMostSpecificCheck(Request $request, string $modules, ?string $action): bool
    {
        $checks = array_values(array_filter(
            $request->route()?->gatherMiddleware() ?? [],
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'super_admin.can:')
        ));

        if (count($checks) < 2) {
            return true;
        }

        return end($checks) === 'super_admin.can:' . $modules . ($action !== null ? ",{$action}" : '');
    }
}
