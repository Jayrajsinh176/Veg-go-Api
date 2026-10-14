<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\ChangeActivityEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RecordChangeActivity
{
    private const MODULE_LABELS = [
        'admins' => 'Admin Management',
        'roles' => 'Roles & Permissions',
        'customers' => 'Users',
        'restaurants' => 'Restaurants',
        'restaurant-applications' => 'Restaurant Applications',
        'restaurant-offers' => 'Restaurant Offers',
        'rider-applications' => 'Delivery Applications',
        'riders' => 'Delivery Partners',
        'orders' => 'Orders',
        'payments' => 'Payments & Transactions',
        'refunds' => 'Refunds',
        'settlements' => 'Settlements',
        'finance' => 'Finance',
        'menu-items' => 'Menu & Food Items',
        'advertisements' => 'Banners / Advertisements',
        'advertisement' => 'Advertisement Packages',
        'coupons' => 'Coupons',
        'notifications' => 'Notifications',
        'support' => 'Support & Escalations',
        'settings' => 'System Settings',
        'activity-events' => 'Activity Logs',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            !$response->isSuccessful()
            || !in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || !$request->route()
        ) {
            return $response;
        }

        $admin = $request->user();

        if (!$admin instanceof Admin) {
            return $response;
        }

        $segments = $request->segments();
        $apiType = $segments[1] ?? '';
        $resource = $segments[2] ?? 'system';
        $routeAction = Str::afterLast((string) $request->route()->getActionName(), '@');

        $recordId = collect($request->route()->parameters())
            ->first(fn ($value) => is_scalar($value) && ctype_digit((string) $value));

        ChangeActivityEvent::create([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'admin_email' => $admin->email,
            'action' => $this->actionLabel($request, $routeAction),
            'module' => self::MODULE_LABELS[$resource] ?? Str::headline(Str::plural($resource)),
            'target' => $recordId === null
                ? null
                : '#' . Str::upper(Str::substr(Str::singular($resource), 0, 3)) . '-' . $recordId,
            'method' => $request->method(),
            'path' => '/' . ltrim($request->path(), '/'),
            'details' => sprintf(
                '%s change recorded through the %s API.',
                $this->actionLabel($request, $routeAction),
                $apiType === 'admin' ? 'Admin' : 'Super Admin'
            ),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
        ]);

        return $response;
    }

    private function actionLabel(Request $request, string $routeAction): string
    {
        $action = Str::camel($routeAction);

        if (in_array($action, ['store', 'create'], true)) {
            return 'Create';
        }

        if ($request->isMethod('DELETE') || in_array($action, ['destroy', 'delete'], true)) {
            return 'Delete';
        }

        return Str::headline($action ?: $request->method());
    }
}
