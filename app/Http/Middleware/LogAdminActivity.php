<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerSupportTicket;
use App\Models\OrderRefund;
use App\Models\Restaurant;
use App\Models\RestaurantAdPackage;
use App\Models\RestaurantAdPackageDuration;
use App\Models\RestaurantAdvertisement;
use App\Models\RestaurantDocument;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantOffer;
use App\Models\RestaurantOrder;
use App\Models\RestaurantSupportTicket;
use App\Models\RestaurantWeeklySettlement;
use App\Models\Rider;
use App\Models\RiderKyc;
use App\Models\RiderSupportTicket;
use App\Models\RiderWeeklySettlement;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records every successful write made from the admin panel and the
 * super admin panel into admin_activity_logs (shown on both Activity
 * Logs pages; the admin panel only sees its own rows).
 *
 * Mapped routes read the affected value before and after the request
 * so the log shows a real "old → new" change. Unmapped routes still
 * get a basic row so no change goes unrecorded.
 */
class LogAdminActivity
{
    /**
     * Writes that are not changes worth auditing
     */
    private const IGNORED = [
        'POST admin/login',
        'POST super-admin/login',
    ];

    /**
     * Module names for routes that fall back to a basic row
     */
    private const MODULE_LABELS = [
        'admins' => 'Admin Management',
        'roles' => 'Roles & Permissions',
        'customers' => 'Users',
        'restaurants' => 'Restaurants',
        'restaurant-applications' => 'Restaurants',
        'restaurant-documents' => 'Restaurants',
        'restaurant-offers' => 'Promotions',
        'rider-applications' => 'Riders',
        'riders' => 'Riders',
        'rider-earnings' => 'Financial',
        'orders' => 'Orders',
        'payments' => 'Financial',
        'refunds' => 'Refunds',
        'settlements' => 'Settlements',
        'finance' => 'Financial',
        'menu-items' => 'Menu',
        'advertisements' => 'Advertisements',
        'advertisement' => 'Advertisements',
        'coupons' => 'Coupons',
        'notifications' => 'Notifications',
        'support' => 'Support',
        'settings' => 'Settings',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $config = $this->routeConfig($request);

        if (!$config) {
            return $next($request);
        }

        $params = $request->route()?->parameters() ?? [];
        $snapshot = $config['snapshot'] ?? null;

        $before = $snapshot ? $this->safely(fn () => $snapshot($params, $request)) : null;

        // Logout deletes the token, so capture the admin first
        $admin = $request->user();

        AdminActivityLog::$recorded = false;

        $response = $next($request);

        // Skip when the controller already wrote its own audit row
        if ($response->getStatusCode() >= 400 || !$admin instanceof Admin || AdminActivityLog::$recorded) {
            return $response;
        }

        $this->safely(function () use ($config, $params, $request, $response, $admin, $before, $snapshot) {
            $after = $snapshot ? $snapshot($params, $request) : null;

            $action = is_callable($config['action'])
                ? $config['action']($before, $after, $request)
                : $config['action'];

            $field = $config['field'] ?? null;
            $old = $before ?? ($config['old'] ?? null);
            $new = $after ?? ($config['new'] ?? null);

            $summary = $config['summary'] ?? null;
            $summary = is_callable($summary) ? $summary($request) : $summary;

            if (!$summary) {
                $summary = $field && ($old !== null || $new !== null)
                    ? $field . ': ' . ($old ?? '—') . ' → ' . ($new ?? '—')
                    : $action;
            }

            $message = json_decode((string) $response->getContent(), true)['message'] ?? null;

            $risk = is_callable($config['risk'] ?? null)
                ? $config['risk']($before, $after, $request)
                : ($config['risk'] ?? 'Low');

            AdminActivityLog::record($admin, $request, [
                'action' => $action,
                'module' => $config['module'],
                'target' => $this->target($config, $params, $response),
                'field' => $field,
                'old_value' => $old,
                'new_value' => $new,
                'change_summary' => $summary,
                'reason' => $request->input('rejection_reason')
                    ?? $request->input('reason')
                    ?? $request->input('description')
                    ?? $request->input('message'),
                'details' => trim(($message ? $message . ' ' : '') . ($config['details'] ?? '')) ?: null,
                'source' => $config['source'] ?? null,
                'risk_level' => $risk,
            ]);
        });

        return $response;
    }

    private function safely(Closure $callback)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::warning('Admin activity log failed: ' . $e->getMessage());

            return null;
        }
    }

    private function target(array $config, array $params, Response $response): ?string
    {
        if (empty($config['target'])) {
            return null;
        }

        if ($config['target'] instanceof Closure) {
            return $config['target'](request(), $params);
        }

        [$prefix, $param] = $config['target'];

        $id = $params[$param] ?? null;

        // Newly created records: read the id from the response
        if ($id === null) {
            $data = json_decode((string) $response->getContent(), true)['data'] ?? [];
            $id = $data['id'] ?? null;
        }

        return $id !== null ? '#' . $prefix . '-' . $id : null;
    }

    private static function label(?string $value): ?string
    {
        return $value === null ? null : Str::headline($value);
    }

    private static function onOff($value, string $on = 'On', string $off = 'Off'): ?string
    {
        return $value === null ? null : ((bool) $value ? $on : $off);
    }

    /**
     * Audited routes of both panels, keyed by "METHOD uri".
     */
    private function routeConfig(Request $request): ?array
    {
        $uri = preg_replace('#^api/#', '', (string) $request->route()?->uri());
        $key = $request->method() . ' ' . $uri;

        if (in_array($key, self::IGNORED, true)) {
            return null;
        }

        $routes = $this->adminRoutes() + $this->superAdminRoutes();

        foreach (['admin', 'super-admin'] as $panel) {
            foreach ($this->sharedRoutes() as $route => $config) {
                [$method, $path] = explode(' ', $route, 2);
                $routes[$method . ' ' . $panel . '/' . $path] ??= $config;
            }
        }

        return $routes[$key] ?? $this->fallback($request, $uri);
    }

    /**
     * Admin panel routes
     */
    private function adminRoutes(): array
    {
        return [
            'POST admin/logout' => [
                'action' => 'Admin Logout',
                'module' => 'Security',
                'source' => 'Admin Authentication',
                'summary' => 'Session: Active → Ended',
                'risk' => 'Low',
            ],

            'PUT admin/customers/{id}' => [
                'module' => 'Users',
                'source' => 'User Management',
                'target' => ['USR', 'id'],
                'field' => 'Status',
                'snapshot' => fn ($p) => self::label(Customer::find($p['id'])?->status),
                'action' => fn ($old, $new) => $old === $new
                    ? 'User Update'
                    : ($new === 'Blocked' ? 'User Block' : 'User Restore'),
                'summary' => fn ($r) => $r->has('status') ? null : 'Profile details updated',
                'risk' => fn ($old, $new) => $new === 'Blocked' && $old !== $new ? 'High' : 'Low',
            ],

            'PUT admin/restaurants/{id}' => [
                'action' => 'Restaurant Update',
                'module' => 'Restaurants',
                'source' => 'Restaurant Management',
                'target' => ['RES', 'id'],
                'field' => 'Name',
                'snapshot' => fn ($p) => Restaurant::find($p['id'])?->restaurant_name,
                'risk' => 'Low',
            ],

            'PUT admin/restaurants/{id}/commission' => [
                'action' => 'Commission Change',
                'module' => 'Financial',
                'source' => 'Finance Management Module',
                'target' => ['RES', 'id'],
                'field' => 'Rate',
                'snapshot' => function ($p) {
                    $rate = Restaurant::find($p['id'])?->commissionRate();

                    return $rate === null ? null : rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%';
                },
                'risk' => 'Medium',
            ],

            'POST admin/restaurants/{id}/suspend' => [
                'action' => 'Restaurant Suspended',
                'module' => 'Restaurants',
                'source' => 'Restaurant Management',
                'target' => ['RES', 'id'],
                'field' => 'Status',
                'snapshot' => fn ($p) => self::label(Restaurant::find($p['id'])?->status),
                'risk' => 'High',
            ],

            'POST admin/restaurants/{id}/unsuspend' => [
                'action' => 'Suspension Reverted',
                'module' => 'Restaurants',
                'source' => 'Restaurant Management',
                'target' => ['RES', 'id'],
                'field' => 'Status',
                'snapshot' => fn ($p) => self::label(Restaurant::find($p['id'])?->status),
                'risk' => 'Medium',
            ],

            'PUT admin/restaurants/{id}/timing' => [
                'action' => 'Timing Update',
                'module' => 'Restaurants',
                'source' => 'Restaurant Timing',
                'target' => ['RES', 'id'],
                'field' => 'Status',
                'snapshot' => fn ($p) => self::onOff(Restaurant::find($p['id'])?->is_online, 'Open', 'Closed'),
                'details' => 'Opening and closing hours were updated.',
                'risk' => 'Low',
            ],

            'PUT admin/payments/{id}' => [
                'action' => 'Payment Update',
                'module' => 'Financial',
                'source' => 'Payments & Transactions',
                'target' => ['ORD', 'id'],
                'field' => 'Payment',
                'snapshot' => function ($p) {
                    $order = RestaurantOrder::with('payment')->find($p['id']);

                    return self::label(strtolower((string) ($order?->payment?->payment_status ?? $order?->payment_status)) ?: null);
                },
                'risk' => fn ($old, $new) => $new === 'Refunded' ? 'High' : 'Medium',
            ],

            'POST admin/rider-earnings/{id}/adjust' => [
                'action' => 'Earning Adjustment',
                'module' => 'Financial',
                'source' => 'Rider Earnings',
                'target' => ['RID', 'id'],
                'field' => 'Wallet',
                'snapshot' => function ($p) {
                    $balance = Rider::find($p['id'])?->wallet_balance;

                    return $balance === null ? null : '₹' . number_format((float) $balance, 2);
                },
                'risk' => 'High',
            ],

            'PUT admin/rider-earnings/{id}/status' => [
                'action' => 'Payout Update',
                'module' => 'Financial',
                'source' => 'Payout Management',
                'target' => ['RID', 'id'],
                'summary' => fn ($r) => 'Payout: Pending → ' . self::label($r->input('status')),
                'old' => 'Pending',
                'risk' => 'Medium',
            ],

            'POST admin/rider-earnings/{id}/contact' => [
                'action' => 'Rider Contacted',
                'module' => 'Riders',
                'source' => 'Rider Earnings',
                'target' => ['RID', 'id'],
                'summary' => 'Message sent to rider',
                'risk' => 'Low',
            ],

            'POST admin/restaurant-documents/{restaurantId}/under-review' => $this->restaurantDocument('Restaurant Under Review', 'Low'),
            'POST admin/restaurant-documents/{restaurantId}/approve' => $this->restaurantDocument('Restaurant Approval', 'Low'),
            'POST admin/restaurant-documents/{restaurantId}/reject' => $this->restaurantDocument('Restaurant Reject', 'Medium'),

            'POST admin/restaurants/{restaurantId}/menu' => [
                'action' => 'Menu Item Added',
                'module' => 'Menu',
                'source' => 'Menu & Food Items',
                'target' => ['MENU', 'itemId'],
                'summary' => fn ($r) => 'Item: — → ' . ($r->input('name') ?? 'New item'),
                'risk' => 'Low',
            ],

            'PUT admin/restaurants/{restaurantId}/menu/{itemId}' => [
                'action' => 'Menu Item Update',
                'module' => 'Menu',
                'source' => 'Menu & Food Items',
                'target' => ['MENU', 'itemId'],
                'field' => 'Price',
                'snapshot' => function ($p) {
                    $price = RestaurantMenuItem::find($p['itemId'])?->price;

                    return $price === null ? null : '₹' . number_format((float) $price, 2);
                },
                'risk' => 'Low',
            ],

            'DELETE admin/restaurants/{restaurantId}/menu/{itemId}' => [
                'action' => 'Menu Item Deleted',
                'module' => 'Menu',
                'source' => 'Menu & Food Items',
                'target' => ['MENU', 'itemId'],
                'summary' => 'Item: Active → Deleted',
                'risk' => 'Medium',
            ],

            'PATCH admin/restaurants/{restaurantId}/menu/{itemId}/availability' => [
                'action' => 'Availability Change',
                'module' => 'Menu',
                'source' => 'Menu & Food Items',
                'target' => ['MENU', 'itemId'],
                'field' => 'Availability',
                'snapshot' => fn ($p) => self::onOff(RestaurantMenuItem::find($p['itemId'])?->is_available, 'Available', 'Unavailable'),
                'risk' => 'Low',
            ],

            'POST admin/restaurants/{restaurantId}/menu/categories' => [
                'action' => 'Category Added',
                'module' => 'Menu',
                'source' => 'Menu & Food Items',
                'target' => ['RES', 'restaurantId'],
                'summary' => fn ($r) => 'Category: — → ' . ($r->input('name') ?? 'New category'),
                'risk' => 'Low',
            ],

            'POST admin/restaurant-offers' => [
                'action' => 'Offer Created',
                'module' => 'Promotions',
                'source' => 'Promotions / Advertisements',
                'target' => ['OFF', 'id'],
                'summary' => fn ($r) => 'Discount: — → ' . $r->input('discount_value')
                    . ($r->input('discount_type') === 'percentage' ? '%' : ' flat'),
                'risk' => 'Medium',
            ],

            'PUT admin/restaurant-offers/{id}' => [
                'action' => 'Offer Update',
                'module' => 'Promotions',
                'source' => 'Promotions / Advertisements',
                'target' => ['OFF', 'id'],
                'field' => 'Discount',
                'snapshot' => function ($p) {
                    $offer = RestaurantOffer::find($p['id']);

                    return $offer ? rtrim(rtrim(number_format((float) $offer->discount_value, 2, '.', ''), '0'), '.') . ($offer->discount_type === 'percentage' ? '%' : ' flat') : null;
                },
                'risk' => 'Medium',
            ],

            'PATCH admin/restaurant-offers/{id}/status' => [
                'action' => 'Offer Status Change',
                'module' => 'Promotions',
                'source' => 'Promotions / Advertisements',
                'target' => ['OFF', 'id'],
                'field' => 'Status',
                'snapshot' => fn ($p) => self::onOff(RestaurantOffer::find($p['id'])?->is_active, 'Active', 'Inactive'),
                'risk' => 'Low',
            ],

            'DELETE admin/restaurant-offers/{id}' => [
                'action' => 'Offer Deleted',
                'module' => 'Promotions',
                'source' => 'Promotions / Advertisements',
                'target' => ['OFF', 'id'],
                'summary' => 'Offer: Active → Deleted',
                'risk' => 'Medium',
            ],

            'POST admin/riders' => [
                'action' => 'Rider Created',
                'module' => 'Riders',
                'source' => 'Delivery Partners',
                'target' => ['RID', 'id'],
                'summary' => fn ($r) => 'Status: — → ' . self::label($r->input('status')),
                'risk' => 'Low',
            ],

            'PATCH admin/riders/{id}/status' => [
                'module' => 'Riders',
                'source' => 'Delivery Partners',
                'target' => ['RID', 'id'],
                'field' => 'Status',
                'snapshot' => fn ($p) => self::label(Rider::find($p['id'])?->status),
                'action' => fn ($old, $new) => $new === 'Blocked' ? 'Rider Block' : 'Rider Status Change',
                'risk' => fn ($old, $new) => $new === 'Blocked' ? 'High' : 'Medium',
            ],

            'PATCH admin/riders/{id}/online' => [
                'action' => 'Rider Availability',
                'module' => 'Riders',
                'source' => 'Rider Location & Availability',
                'target' => ['RID', 'id'],
                'field' => 'Availability',
                'snapshot' => fn ($p) => self::onOff(Rider::find($p['id'])?->is_online, 'Online', 'Offline'),
                'risk' => 'Low',
            ],

            'PATCH admin/riders/{id}/documents/status' => [
                'action' => 'Rider KYC Review',
                'module' => 'Riders',
                'source' => 'Delivery Applications',
                'target' => ['RID', 'id'],
                'field' => 'Verification',
                'snapshot' => fn ($p) => self::label(RiderKyc::where('rider_id', $p['id'])->value('status')),
                'risk' => fn ($old, $new) => $new === 'Rejected' ? 'Medium' : 'Low',
            ],

            'PATCH admin/riders/{id}/documents/verify' => [
                'action' => 'Rider Document Verify',
                'module' => 'Riders',
                'source' => 'Delivery Applications',
                'target' => ['RID', 'id'],
                'summary' => fn ($r) => self::label($r->input('document')) . ': '
                    . ($r->boolean('verified') ? 'Unverified → Verified' : 'Verified → Unverified'),
                'risk' => 'Low',
            ],
        ];
    }

    /**
     * Super admin panel routes. Routes whose controller already writes
     * its own audit row (admins, roles, restaurants, orders, payments,
     * commission, restaurant applications) are deduplicated in handle().
     */
    private function superAdminRoutes(): array
    {
        $admin = $this->adminRoutes();

        return [
            'POST super-admin/logout' => [
                'action' => 'Super Admin Logout',
                'module' => 'Security',
                'source' => 'Super Admin Authentication',
                'summary' => 'Session: Active → Ended',
                'risk' => 'Low',
            ],

            'PUT super-admin/settings' => [
                'action' => 'Settings Update',
                'module' => 'Settings',
                'source' => 'Platform Settings',
                'summary' => fn ($r) => 'Updated: ' . collect(array_keys($r->except(['_method'])))
                    ->map(fn ($key) => Str::headline($key))
                    ->take(6)
                    ->implode(', '),
                'risk' => 'High',
            ],

            'POST super-admin/settings/logo' => [
                'action' => 'Logo Updated',
                'module' => 'Settings',
                'source' => 'Platform Settings',
                'summary' => 'Logo: Old → New',
                'risk' => 'Low',
            ],

            'DELETE super-admin/settings/logo' => [
                'action' => 'Logo Removed',
                'module' => 'Settings',
                'source' => 'Platform Settings',
                'summary' => 'Logo: Set → Removed',
                'risk' => 'Low',
            ],

            'POST super-admin/customers' => [
                'action' => 'User Created',
                'module' => 'Users',
                'source' => 'User Management',
                'target' => ['USR', 'id'],
                'summary' => fn ($r) => 'User: — → ' . ($r->input('name') ?? 'New user'),
                'risk' => 'Low',
            ],

            'PATCH super-admin/customers/status' => [
                'module' => 'Users',
                'source' => 'User Management',
                'target' => function ($r) {
                    $ids = collect($r->input('ids', []));

                    return $ids->count() > 3
                        ? $ids->count() . ' users'
                        : $ids->map(fn ($id) => '#USR-' . $id)->implode(', ');
                },
                'field' => 'Status',
                'action' => fn ($old, $new, $r) => $r->input('status') === 'blocked' ? 'User Block' : 'User Restore',
                'summary' => fn ($r) => 'Status: → ' . self::label($r->input('status'))
                    . ' (' . count((array) $r->input('ids', [])) . ' user(s))',
                'risk' => fn ($old, $new, $r) => $r->input('status') === 'blocked' ? 'High' : 'Medium',
            ],

            'PUT super-admin/customers/{id}' => $admin['PUT admin/customers/{id}'],

            'PATCH super-admin/restaurants/{id}/menu/{itemId}/availability' => [
                'action' => 'Availability Change',
                'module' => 'Menu',
                'source' => 'Restaurants',
                'target' => ['MENU', 'itemId'],
                'field' => 'Availability',
                'snapshot' => fn ($p) => self::onOff(RestaurantMenuItem::find($p['itemId'])?->is_available, 'Available', 'Unavailable'),
                'risk' => 'Low',
            ],

            'POST super-admin/restaurant-offers' => $admin['POST admin/restaurant-offers'],
            'PUT super-admin/restaurant-offers/{id}' => $admin['PUT admin/restaurant-offers/{id}'],
            'PATCH super-admin/restaurant-offers/{id}/status' => $admin['PATCH admin/restaurant-offers/{id}/status'],
            'DELETE super-admin/restaurant-offers/{id}' => $admin['DELETE admin/restaurant-offers/{id}'],

            'POST super-admin/coupons' => [
                'action' => 'Coupon Created',
                'module' => 'Coupons',
                'source' => 'Coupons & Promotions',
                'target' => ['COU', 'id'],
                'summary' => fn ($r) => 'Coupon: — → ' . Str::upper((string) $r->input('code')),
                'risk' => 'Medium',
            ],

            'PATCH super-admin/coupons/{id}/status' => [
                'action' => 'Coupon Status Change',
                'module' => 'Coupons',
                'source' => 'Coupons & Promotions',
                'target' => ['COU', 'id'],
                'field' => 'Status',
                'snapshot' => fn ($p) => Coupon::find($p['id'])?->computed_status,
                'risk' => 'Medium',
            ],

            'DELETE super-admin/coupons/{id}' => [
                'action' => 'Coupon Deleted',
                'module' => 'Coupons',
                'source' => 'Coupons & Promotions',
                'target' => ['COU', 'id'],
                'summary' => 'Coupon: Active → Deleted',
                'risk' => 'Medium',
            ],

            'POST super-admin/menu-items' => [
                'action' => 'Menu Item Added',
                'module' => 'Menu',
                'source' => 'Menu & Food Items',
                'target' => ['MENU', 'id'],
                'summary' => fn ($r) => 'Item: — → ' . ($r->input('name') ?? 'New item'),
                'risk' => 'Low',
            ],

            'POST super-admin/menu-items/{id}' => [
                'action' => 'Menu Item Update',
                'module' => 'Menu',
                'source' => 'Menu & Food Items',
                'target' => ['MENU', 'id'],
                'field' => 'Price',
                'snapshot' => function ($p) {
                    $price = RestaurantMenuItem::find($p['id'])?->price;

                    return $price === null ? null : '₹' . number_format((float) $price, 2);
                },
                'risk' => 'Low',
            ],

            'PATCH super-admin/menu-items/{id}/status' => [
                'action' => 'Availability Change',
                'module' => 'Menu',
                'source' => 'Menu & Food Items',
                'target' => ['MENU', 'id'],
                'field' => 'Availability',
                'snapshot' => fn ($p) => self::onOff(RestaurantMenuItem::find($p['id'])?->is_available, 'Available', 'Unavailable'),
                'risk' => 'Low',
            ],

            'DELETE super-admin/menu-items/{id}' => [
                'action' => 'Menu Item Deleted',
                'module' => 'Menu',
                'source' => 'Menu & Food Items',
                'target' => ['MENU', 'id'],
                'summary' => 'Item: Active → Deleted',
                'risk' => 'Medium',
            ],

            'POST super-admin/riders' => [
                'action' => 'Rider Created',
                'module' => 'Riders',
                'source' => 'Delivery Partners',
                'target' => ['RID', 'id'],
                'summary' => fn ($r) => 'Rider: — → ' . ($r->input('name') ?? 'New rider'),
                'risk' => 'Low',
            ],

            'PATCH super-admin/riders/{id}/online' => $admin['PATCH admin/riders/{id}/online'],

            'POST super-admin/rider-applications/{id}/documents/{key}/verify' => [
                'action' => 'Rider Document Verify',
                'module' => 'Riders',
                'source' => 'Delivery Applications',
                'target' => ['RID', 'id'],
                'summary' => fn ($r) => self::label($r->route('key')) . ': Unverified → Verified',
                'risk' => 'Low',
            ],

            'POST super-admin/rider-applications/{id}/approve' => $this->riderApplication('Rider Application Approved', 'Low'),
            'POST super-admin/rider-applications/{id}/reject' => $this->riderApplication('Rider Application Rejected', 'Medium'),

            'POST super-admin/refunds/{id}/approve' => $this->refund('Refund Approved'),
            'POST super-admin/refunds/{id}/reject' => $this->refund('Refund Rejected'),

            'PUT super-admin/finance/riders/{id}/status' => $admin['PUT admin/rider-earnings/{id}/status'],

            'POST super-admin/settlements/run-batch' => $this->settlementBatch('Rider'),
            'POST super-admin/settlements/{id}/mark-paid' => $this->settlement(RiderWeeklySettlement::class, 'Settlement Marked Paid', 'High'),
            'POST super-admin/settlements/{id}/retry' => $this->settlement(RiderWeeklySettlement::class, 'Settlement Retry', 'Medium'),
            'PATCH super-admin/settlements/{id}/hold' => $this->settlement(RiderWeeklySettlement::class, 'Settlement Hold', 'Medium'),
            'POST super-admin/settlements/{id}/cod-settle' => $this->settlement(RiderWeeklySettlement::class, 'COD Settled', 'High'),
            'POST super-admin/settlements/{id}/contact' => $this->contact(RiderWeeklySettlement::class, 'Rider Contacted'),

            'POST super-admin/settlements/restaurants/run-batch' => $this->settlementBatch('Restaurant'),
            'POST super-admin/settlements/restaurants/{id}/mark-paid' => $this->settlement(RestaurantWeeklySettlement::class, 'Settlement Marked Paid', 'High'),
            'POST super-admin/settlements/restaurants/{id}/retry' => $this->settlement(RestaurantWeeklySettlement::class, 'Settlement Retry', 'Medium'),
            'PATCH super-admin/settlements/restaurants/{id}/hold' => $this->settlement(RestaurantWeeklySettlement::class, 'Settlement Hold', 'Medium'),
            'POST super-admin/settlements/restaurants/{id}/contact' => $this->contact(RestaurantWeeklySettlement::class, 'Restaurant Contacted'),

            'PUT super-admin/advertisement/packages/{id}' => [
                'action' => 'Ad Package Update',
                'module' => 'Advertisements',
                'source' => 'Advertisement Packages',
                'target' => ['PKG', 'id'],
                'field' => 'Status',
                'snapshot' => fn ($p) => self::onOff(RestaurantAdPackage::find($p['id'])?->is_active, 'Active', 'Inactive'),
                'risk' => 'Medium',
            ],

            'POST super-admin/advertisement/packages/{id}/durations' => [
                'action' => 'Ad Pricing Added',
                'module' => 'Advertisements',
                'source' => 'Advertisement Packages',
                'target' => ['PKG', 'id'],
                'summary' => fn ($r) => 'Price: — → ₹' . number_format((float) $r->input('price'), 2)
                    . ' (' . $r->input('duration_days') . ' days)',
                'risk' => 'Medium',
            ],

            'PUT super-admin/advertisement/package-durations/{id}' => [
                'action' => 'Ad Pricing Update',
                'module' => 'Advertisements',
                'source' => 'Advertisement Packages',
                'target' => ['DUR', 'id'],
                'field' => 'Price',
                'snapshot' => function ($p) {
                    $price = RestaurantAdPackageDuration::find($p['id'])?->price;

                    return $price === null ? null : '₹' . number_format((float) $price, 2);
                },
                'risk' => 'Medium',
            ],

            'POST super-admin/notifications/broadcast' => [
                'action' => 'Notification Broadcast',
                'module' => 'Notifications',
                'source' => 'Communication & Notifications',
                'summary' => fn ($r) => '"' . Str::limit((string) $r->input('title'), 60) . '" → '
                    . $r->input('segment') . ' (' . ($r->input('action') ?? 'Send') . ')',
                'risk' => 'Medium',
            ],

            'POST super-admin/restaurant-applications/{id}/notes' => [
                'action' => 'Application Note Added',
                'module' => 'Restaurants',
                'source' => 'Restaurant Applications',
                'target' => ['RES', 'id'],
                'summary' => 'Internal note added',
                'risk' => 'Low',
            ],
        ];
    }

    /**
     * Routes that exist on both panels under the same path
     */
    private function sharedRoutes(): array
    {
        $ad = fn (string $action, string $risk = 'Medium') => [
            'action' => $action,
            'module' => 'Advertisements',
            'source' => 'Promotions / Advertisements',
            'target' => ['ADV', 'id'],
            'field' => 'Status',
            'snapshot' => fn ($p) => isset($p['id']) ? self::label(RestaurantAdvertisement::find($p['id'])?->status) : null,
            'risk' => $risk,
        ];

        return [
            'POST advertisements' => array_merge($ad('Advertisement Created'), ['snapshot' => null, 'field' => null, 'summary' => 'Advertisement: — → Created']),
            'PUT advertisements/{id}' => array_merge($ad('Advertisement Update', 'Low'), ['summary' => 'Advertisement details updated']),
            'POST advertisements/{id}/payment' => $ad('Advertisement Payment Started', 'Low'),
            'POST advertisements/{id}/payment/verify' => $ad('Advertisement Payment Verified'),
            'POST advertisements/{id}/activate' => $ad('Advertisement Activated'),
            'POST advertisements/{id}/pause' => $ad('Advertisement Paused'),
            'POST advertisements/{id}/resume' => $ad('Advertisement Resumed'),
            'POST advertisements/{id}/cancel' => $ad('Advertisement Cancelled', 'High'),
            'POST advertisements/{id}/mark-paid' => $ad('Advertisement Marked Paid', 'High'),
            'POST advertisements/{id}/mark-refunded' => $ad('Advertisement Refunded', 'High'),

            'POST support/tickets/{ticketNumber}/messages' => [
                'action' => 'Ticket Reply',
                'module' => 'Support',
                'source' => 'Support & Escalations',
                'target' => fn ($r, $p) => '#' . $p['ticketNumber'],
                'summary' => 'Reply sent to ticket',
                'risk' => 'Low',
            ],

            'PATCH support/tickets/{ticketNumber}' => [
                'action' => 'Ticket Update',
                'module' => 'Support',
                'source' => 'Support & Escalations',
                'target' => fn ($r, $p) => '#' . $p['ticketNumber'],
                'field' => 'Status',
                'snapshot' => fn ($p) => self::label(
                    RiderSupportTicket::where('ticket_number', $p['ticketNumber'])->value('status')
                        ?? RestaurantSupportTicket::where('ticket_number', $p['ticketNumber'])->value('status')
                        ?? CustomerSupportTicket::where('ticket_number', $p['ticketNumber'])->value('status')
                ),
                'risk' => 'Low',
            ],

            'POST rider-earnings/{id}/cod-settle' => $this->codSettle(),
            'POST finance/riders/{id}/cod-settle' => $this->codSettle(),
        ];
    }

    /**
     * Basic row for any write route that has no entry above
     */
    private function fallback(Request $request, string $uri): array
    {
        $segments = explode('/', $uri);
        $resource = $segments[1] ?? 'system';
        $method = Str::camel(Str::afterLast((string) $request->route()?->getActionName(), '@'));
        $noun = Str::headline(Str::singular($resource));

        $verb = match (true) {
            in_array($method, ['store', 'create'], true) => 'Created',
            $request->isMethod('DELETE') || in_array($method, ['destroy', 'delete'], true) => 'Deleted',
            $method === 'update' => 'Updated',
            default => Str::headline($method ?: $request->method()),
        };

        $param = collect(array_keys($request->route()?->parameters() ?? []))->first() ?? 'id';
        $panel = $segments[0] === 'super-admin' ? 'Super Admin Panel' : 'Admin Panel';

        return [
            'action' => $noun . ' ' . $verb,
            'module' => self::MODULE_LABELS[$resource] ?? Str::headline($resource),
            'source' => $panel,
            'target' => [Str::upper(Str::substr(Str::singular($resource), 0, 3)), $param],
            'risk' => $request->isMethod('DELETE') ? 'Medium' : 'Low',
        ];
    }

    private function riderApplication(string $action, string $risk): array
    {
        return [
            'action' => $action,
            'module' => 'Riders',
            'source' => 'Delivery Applications',
            'target' => ['RID', 'id'],
            'field' => 'Verification',
            'snapshot' => fn ($p) => self::label(RiderKyc::where('rider_id', $p['id'])->value('status')),
            'risk' => $risk,
        ];
    }

    private function refund(string $action): array
    {
        return [
            'action' => $action,
            'module' => 'Refunds',
            'source' => 'Refunds Command Center',
            'target' => ['REF', 'id'],
            'field' => 'Refund',
            'snapshot' => fn ($p) => self::label(OrderRefund::find($p['id'])?->status),
            'risk' => 'High',
        ];
    }

    private function settlement(string $model, string $action, string $risk): array
    {
        return [
            'action' => $action,
            'module' => 'Settlements',
            'source' => $model === RiderWeeklySettlement::class ? 'Rider Settlements' : 'Restaurant Settlements',
            'target' => ['SET', 'id'],
            'field' => 'Status',
            'snapshot' => fn ($p) => self::label($model::find($p['id'])?->status),
            'risk' => $risk,
        ];
    }

    private function contact(string $model, string $action): array
    {
        return array_merge($this->settlement($model, $action, 'Low'), [
            'field' => null,
            'snapshot' => null,
            'summary' => 'Message sent about settlement',
        ]);
    }

    private function settlementBatch(string $type): array
    {
        return [
            'action' => 'Settlement Batch Run',
            'module' => 'Settlements',
            'source' => $type . ' Settlements',
            'summary' => $type . ' payouts: Pending → Processing',
            'risk' => 'High',
        ];
    }

    private function codSettle(): array
    {
        return [
            'action' => 'COD Settled',
            'module' => 'Financial',
            'source' => 'Rider Earnings',
            'target' => ['RID', 'id'],
            'field' => 'Wallet',
            'snapshot' => function ($p) {
                $balance = Rider::find($p['id'])?->wallet_balance;

                return $balance === null ? null : '₹' . number_format((float) $balance, 2);
            },
            'risk' => 'High',
        ];
    }

    private function restaurantDocument(string $action, string $risk): array
    {
        return [
            'action' => $action,
            'module' => 'Restaurants',
            'source' => 'Restaurant Verification Module',
            'target' => ['RES', 'restaurantId'],
            'field' => 'Verification',
            'snapshot' => fn ($p) => self::label(
                RestaurantDocument::where('restaurant_id', $p['restaurantId'])->value('status')
            ),
            'risk' => $risk,
        ];
    }
}
