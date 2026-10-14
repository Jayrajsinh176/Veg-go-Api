<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Models\RestaurantDocument;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantOffer;
use App\Models\RestaurantOrder;
use App\Models\Rider;
use App\Models\RiderKyc;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records every successful write made from the admin panel
 * into admin_activity_logs (shown on the Activity Logs page).
 *
 * Each audited route reads the affected value before and after
 * the request so the log shows a real "old → new" change.
 */
class LogAdminActivity
{
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

        $response = $next($request);

        if ($response->getStatusCode() >= 400 || !$admin instanceof Admin) {
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
                'source' => $config['source'],
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
     * Audited admin routes, keyed by "METHOD uri".
     */
    private function routeConfig(Request $request): ?array
    {
        $uri = preg_replace('#^api/#', '', (string) $request->route()?->uri());
        $key = $request->method() . ' ' . $uri;

        $routes = [
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

        return $routes[$key] ?? null;
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
