<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderPayment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPaymentController extends Controller
{
    /**
     * Payment transactions (one per customer order) in the range.
     * range: 7d | 30d | 90d | year
     */
    public function index(Request $request)
    {
        $startDate = $this->rangeStart($request->query('range', '7d'));

        $orders = RestaurantOrder::query()
            ->select([
                'id',
                'customer_id',
                'restaurant_id',
                'order_number',
                'total_amount',
                'payment_method',
                'payment_status',
                'placed_at',
                'created_at',
            ])
            ->with([
                'customer:id,name,phone,email',
                'restaurant:id,restaurant_name',
                'payment:id,order_id,payment_method,payment_status,total_amount,transaction_id,payment_gateway,paid_at',
            ])
            ->where('created_at', '>=', $startDate)
            ->latest('id')
            ->paginate(100);

        $orders->getCollection()->transform(
            fn ($order) => $this->transform($order)
        );

        return response()->json([
            'status' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Update the payment status of an order.
     * payment_status: pending | paid | failed | refunded
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'payment_status' => [
                'required',
                Rule::in(['pending', 'paid', 'failed', 'refunded']),
            ],
        ]);

        $order = RestaurantOrder::with('payment')->findOrFail($id);

        $currentStatus = strtolower(
            $order->payment?->payment_status ?? $order->payment_status
        );

        if (
            $validated['payment_status'] === 'refunded'
            && ! in_array($currentStatus, ['paid', 'refunded'])
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Only successful payments can be refunded.',
            ], 422);
        }

        DB::transaction(function () use ($order, $validated) {
            $status = $validated['payment_status'];

            $order->update([
                'payment_status' => $status,
            ]);

            if ($order->payment) {
                $order->payment->update([
                    'payment_status' => strtoupper($status),
                    'paid_at' => $status === 'paid'
                        ? ($order->payment->paid_at ?? now())
                        : $order->payment->paid_at,
                ]);
            } else {
                RestaurantOrderPayment::create([
                    'order_id' => $order->id,
                    'payment_method' => $this->paymentRowMethod($order->payment_method),
                    'payment_status' => strtoupper($status),
                    'order_amount' => $order->subtotal ?? $order->total_amount,
                    'delivery_fee' => $order->delivery_charge ?? 0,
                    'packing_charge' => $order->packing_charge ?? 0,
                    'tax' => $order->tax ?? 0,
                    'discount' => $order->discount ?? 0,
                    'total_amount' => $order->total_amount,
                    'paid_at' => $status === 'paid' ? now() : null,
                ]);
            }
        });

        $order->load([
            'customer:id,name,phone,email',
            'restaurant:id,restaurant_name',
            'payment:id,order_id,payment_method,payment_status,total_amount,transaction_id,payment_gateway,paid_at',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Payment status updated successfully.',
            'data' => $this->transform($order),
        ]);
    }

    private function transform(RestaurantOrder $order): array
    {
        $payment = $order->payment;

        $method = strtolower($order->payment_method ?? '');

        if (! $method && $payment) {
            $method = match ($payment->payment_method) {
                'COD' => 'cash',
                'WALLET' => 'wallet',
                default => 'online',
            };
        }

        $date = $payment?->paid_at
            ?? ($order->placed_at ? Carbon::parse($order->placed_at) : $order->created_at);

        return [
            'id' => $order->id,
            'transaction_id' => $payment?->transaction_id
                ?: 'TXN-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            'order_number' => $order->order_number,
            'customer' => [
                'id' => $order->customer?->id,
                'name' => $order->customer?->name,
                'email' => $order->customer?->email,
                'phone' => $order->customer?->phone,
            ],
            'restaurant_name' => $order->restaurant?->restaurant_name,
            'amount' => (float) ($payment?->total_amount ?? $order->total_amount),
            'payment_method' => $method,
            'payment_gateway' => $payment?->payment_gateway,
            'payment_status' => strtolower($payment?->payment_status ?? $order->payment_status ?? 'pending'),
            'paid_at' => $payment?->paid_at,
            'date' => $date?->toIso8601String(),
        ];
    }

    private function paymentRowMethod(?string $method): string
    {
        return match ($method) {
            'cash' => 'COD',
            'wallet' => 'WALLET',
            default => 'ONLINE',
        };
    }

    private function rangeStart(string $range): Carbon
    {
        return match ($range) {
            '30d' => now()->subDays(30)->startOfDay(),
            '90d' => now()->subDays(90)->startOfDay(),
            'year' => now()->startOfYear(),
            default => now()->subDays(7)->startOfDay(),
        };
    }
}
