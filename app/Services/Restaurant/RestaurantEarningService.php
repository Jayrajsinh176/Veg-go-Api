<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant;
use App\Models\RestaurantOrder;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant earning per order.
 *
 *   commission = subtotal × rate (restaurant's own rate, else 30%)
 *   earning    = subtotal − commission
 *
 * ready_for_pickup -> calculate()  : figures saved, earning_status stays pending
 * delivered        -> credit()     : wallet credited, earning_status credited
 * cancelled        -> reverse()    : pending -> reversed, credited -> wallet debited
 */
class RestaurantEarningService
{
    public function __construct(
        protected RestaurantSettlementService $settlementService
    ) {
    }

    /**
     * Save commission and earning on the order at handover (no wallet change)
     */
    public function calculate(RestaurantOrder $order, ?Restaurant $restaurant = null): RestaurantOrder
    {
        if (in_array($order->earning_status, ['credited', 'reversed'], true)) {
            return $order;
        }

        $restaurant ??= $order->restaurant;

        $rate = $restaurant->commissionRate();
        $commission = round(((float) $order->subtotal * $rate) / 100, 2);

        $order->update([
            'commission_percentage' => $rate,
            'commission_amount' => $commission,
            'restaurant_earning' => round((float) $order->subtotal - $commission, 2),
        ]);

        return $order;
    }

    /**
     * Credit the restaurant wallet once the order is delivered
     */
    public function credit(RestaurantOrder $order): RestaurantOrder
    {
        return DB::transaction(function () use ($order) {
            $order = RestaurantOrder::lockForUpdate()->find($order->id);

            // Prevent duplicate wallet credit
            if (!$order || $order->earning_status !== 'pending') {
                return $order;
            }

            $restaurant = Restaurant::lockForUpdate()->findOrFail($order->restaurant_id);

            // Figures from handover; calculate now if the order skipped it
            if ($order->commission_percentage === null) {
                $this->calculate($order, $restaurant);
            }

            $earning = (float) $order->restaurant_earning;

            $settlement = $this->settlementService->settlementFor($restaurant);

            $this->settlementService->record(
                $restaurant,
                'credit',
                $earning,
                'Order #' . $order->order_number . ' payment',
                $this->breakdown($order),
                'restaurant_order',
                $order->id,
                $settlement,
            );

            $settlement->increment('orders_count');
            $settlement->increment('gross_sales', (float) $order->subtotal);
            $settlement->increment('commission', (float) $order->commission_amount);
            $settlement->increment('total_amount', $earning);

            $order->update([
                'earning_status' => 'credited',
                'earning_credited_at' => now(),
            ]);

            return $order->fresh();
        });
    }

    /**
     * Order cancelled: drop a pending earning, or take back a credited one
     */
    public function reverse(RestaurantOrder $order, ?string $reason = null): RestaurantOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = RestaurantOrder::lockForUpdate()->find($order->id);

            if (!$order || $order->earning_status === 'reversed') {
                return $order;
            }

            if ($order->earning_status === 'credited' && (float) $order->restaurant_earning > 0) {
                $restaurant = Restaurant::lockForUpdate()->findOrFail($order->restaurant_id);

                $settlement = $this->settlementService->settlementFor($restaurant);

                $this->settlementService->record(
                    $restaurant,
                    'debit',
                    (float) $order->restaurant_earning,
                    'Order #' . $order->order_number . ' reversed',
                    'Earning taken back because the order was cancelled'
                        . ($reason ? ': ' . $reason : '') . '. ' . $this->breakdown($order),
                    'restaurant_order_reversal',
                    $order->id,
                    $settlement,
                );

                $settlement->decrement('total_amount', (float) $order->restaurant_earning);
            }

            $order->update(['earning_status' => 'reversed']);

            return $order->fresh();
        });
    }

    /**
     * Kept for existing callers (super admin status change / clear payout)
     */
    public function creditOrderEarning(RestaurantOrder $order): RestaurantOrder
    {
        return $this->credit($order);
    }

    /**
     * "Subtotal ₹360.00 − Commission 30% ₹108.00 = Earning ₹252.00"
     */
    public function breakdown(RestaurantOrder $order): string
    {
        return 'Subtotal ₹' . number_format((float) $order->subtotal, 2)
            . ' − Commission ' . rtrim(rtrim(number_format((float) $order->commission_percentage, 2), '0'), '.') . '%'
            . ' ₹' . number_format((float) $order->commission_amount, 2)
            . ' = Earning ₹' . number_format((float) $order->restaurant_earning, 2);
    }
}
