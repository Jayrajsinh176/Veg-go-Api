<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\RestaurantOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminPaymentController extends Controller
{
    /**
     * Max transactions returned to the Payments page
     */
    private const LIST_LIMIT = 2000;

    /**
     * restaurant_order_payments / restaurant_orders payment status -> page status
     */
    private const STATUS_MAP = [
        'paid' => 'SETTLED',
        'pending' => 'PENDING',
        'failed' => 'FAILED',
        'refunded' => 'REFUNDED',
    ];

    // =====================================================
    // LIST (GET /api/super-admin/payments)
    // =====================================================

    public function index(Request $request)
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:all,today,7,30,90'],
        ]);

        $range = $validated['range'] ?? '30';

        $since = match ($range) {
            'today' => now()->startOfDay(),
            '7', '30', '90' => now()->subDays((int) $range),
            default => null,
        };

        $orders = RestaurantOrder::query()
            ->when($since, fn ($query) => $query->whereRaw('COALESCE(placed_at, created_at) >= ?', [$since]))
            ->with([
                'restaurant:id,restaurant_name',
                'customer:id,name,phone,email',
                'deliveryAddress:id,receiver_name,receiver_phone',
                'payment',
            ])
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return response()->json([
            'message' => 'Transactions fetched successfully.',
            'data' => [
                'transactions' => $orders->map(fn ($order) => $this->transform($order))->values(),
            ],
        ]);
    }

    // =====================================================
    // MARK SETTLED (PATCH /api/super-admin/payments/{id}/settle)
    // =====================================================

    public function settle(Request $request, $id)
    {
        $order = RestaurantOrder::with('payment')->find($id);

        if (!$order) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        if ($this->rawStatus($order) !== 'pending') {
            return response()->json([
                'message' => 'Only pending transactions can be marked as settled.',
            ], 422);
        }

        DB::transaction(function () use ($order) {
            $order->update(['payment_status' => 'paid']);

            $order->payment?->update([
                'payment_status' => 'PAID',
                'paid_at' => $order->payment->paid_at ?? now(),
            ]);
        });

        $this->logPayment($request, $order, 'Marked Payment Settled', 'Payment marked as settled');

        return response()->json([
            'message' => 'Transaction marked as settled.',
            'data' => ['transaction' => $this->transform($order->fresh(['restaurant', 'customer', 'deliveryAddress', 'payment']))],
        ]);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function transform(RestaurantOrder $order): array
    {
        $payment = $order->payment;
        $customer = $order->customer;
        $address = $order->deliveryAddress;
        $status = self::STATUS_MAP[$this->rawStatus($order)] ?? 'PENDING';
        $method = strtolower((string) ($payment?->payment_method ?? $order->payment_method));
        $amount = (float) ($payment?->total_amount ?? $order->total_amount);

        $refundedAt = $status === 'REFUNDED' ? ($payment?->updated_at ?? $order->updated_at) : null;

        return [
            'dbId' => $order->id,
            'id' => $payment?->transaction_id ?: 'TXN-' . str_pad((string) ($payment?->id ?? $order->id), 6, '0', STR_PAD_LEFT),
            'orderId' => $order->order_number,
            'customer' => $customer?->name ?: ($address?->receiver_name ?: ($customer?->phone ?? 'Customer')),
            'customerId' => $order->customer_id ? 'CUST-' . $order->customer_id : null,
            'customerEmail' => $customer?->email ?: ($customer?->phone ?? null),
            'restaurant' => $order->restaurant?->restaurant_name,
            'amount' => $amount,
            'status' => $status,
            'orderStatus' => $order->order_status,
            'paymentMethod' => match ($method) {
                'cash', 'cod' => 'Cash on Delivery',
                'online' => 'Online',
                'wallet' => 'Wallet',
                default => $method ? ucfirst($method) : '—',
            },
            'gateway' => $payment?->payment_gateway,
            'transactionRef' => $payment?->transaction_id ?: '—',
            'placedAt' => $this->iso($order->placed_at ?? $order->created_at),
            'paidAt' => $this->iso($payment?->paid_at),
            'deliveredAt' => $this->iso($order->delivered_at),
            'cancelledAt' => $this->iso($order->cancelled_at),
            'refund' => $status === 'REFUNDED'
                ? [
                    'status' => 'COMPLETED',
                    'type' => 'FULL',
                    'amount' => $amount,
                    'reason' => $order->cancel_reason ?: 'Payment refunded',
                    'requestedAt' => $this->iso($order->cancelled_at ?? $refundedAt),
                    'completedAt' => $this->iso($refundedAt),
                ]
                : null,
        ];
    }

    /**
     * The order's payment status: delivery flows (e.g. COD marked paid on delivery)
     * update restaurant_orders, so the payment record can lag behind it
     */
    private function rawStatus(RestaurantOrder $order): string
    {
        return strtolower((string) ($order->payment_status ?: $order->payment?->payment_status));
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function logPayment(Request $request, RestaurantOrder $order, string $action, string $summary): void
    {
        // Logging must never break the actual change
        try {
            $admin = $request->user();

            AdminActivityLog::create([
                'admin_id' => $admin?->id,
                'admin_name' => $admin?->name,
                'admin_email' => $admin?->email,
                'action' => $action,
                'module' => 'Payments',
                'target' => '#' . $order->order_number,
                'change_summary' => $summary,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'source' => 'Super Admin Panel',
                'method' => $request->method(),
                'path' => $request->path(),
                'risk_level' => 'Medium',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
