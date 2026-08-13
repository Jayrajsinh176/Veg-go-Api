<?php

namespace App\Services\Restaurant;

use App\Models\RestaurantOrder;
use App\Models\RestaurantWalletTransaction;
use Illuminate\Support\Facades\DB;

class RestaurantEarningService
{
    /**
     * Credit restaurant earning for an order.
     */
    public function creditOrderEarning(RestaurantOrder $order): RestaurantOrder
    {
        return DB::transaction(function () use ($order) {

            // Prevent duplicate wallet credit
            if ($order->earning_status === 'credited') {
                return $order->fresh();
            }

            // Get configured VegGo commission
            $commissionPercentage = config(
                'restaurant.commission_percentage',
                20.00
            );

            // Calculate commission
            $commissionAmount = round(
                ($order->total_amount * $commissionPercentage) / 100,
                2
            );

            // Calculate restaurant earning
            $restaurantEarning = round(
                $order->total_amount - $commissionAmount,
                2
            );

            // Lock restaurant row while updating wallet
            $restaurant = $order->restaurant()
                ->lockForUpdate()
                ->firstOrFail();

            // Update order earning details
            $order->update([
                'commission_percentage' => $commissionPercentage,
                'commission_amount' => $commissionAmount,
                'restaurant_earning' => $restaurantEarning,
                'earning_status' => 'credited',
                'earning_credited_at' => now(),
            ]);

            // Update restaurant wallet
            $restaurant->wallet_balance = round(
                $restaurant->wallet_balance + $restaurantEarning,
                2
            );

            $restaurant->save();

            // Create wallet transaction
            RestaurantWalletTransaction::create([
                'restaurant_id' => $restaurant->id,
                'type' => 'credit',
                'amount' => $restaurantEarning,
                'balance_after' => $restaurant->wallet_balance,
                'title' => 'Order #' . $order->order_number . ' payment',
                'description' => 'Restaurant earning credited after order handover.',
                'reference_type' => 'restaurant_order',
                'reference_id' => $order->id,
            ]);

            return $order->fresh();
        });
    }
}