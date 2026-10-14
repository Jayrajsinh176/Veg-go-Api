<?php

namespace App\Services\Order;

use App\Models\DeliveryOrder;
use App\Models\OrderStatusHistory;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderPayment;
use App\Services\Notification\OrderNotificationService;
use App\Services\Restaurant\RestaurantEarningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single place that cancels a customer order.
 *
 * Every cancel path (customer, restaurant reject, super admin, unpaid
 * auto-expiry) goes through cancel(), so they all:
 *   - lock the order and re-check its status
 *   - write the cancel fields and a status history row
 *   - cancel the rider delivery order
 *   - fail unpaid payments (paid ones are left for a manual refund)
 *   - reverse the restaurant earning
 *   - notify the other parties
 */
class OrderCancellationService
{
    public const BY_CUSTOMER = 'customer';
    public const BY_RESTAURANT = 'restaurant';
    public const BY_ADMIN = 'admin';
    public const BY_SYSTEM = 'system';

    /**
     * Customer, restaurant and system can only cancel before the
     * restaurant accepts.
     */
    public const PENDING_ONLY = ['pending'];

    /**
     * Admin can cancel until the rider picks the food up.
     */
    public const ADMIN_CANCELLABLE = [
        'pending',
        'accepted',
        'preparing',
        'ready_for_pickup',
    ];

    public function __construct(
        private RestaurantEarningService $earnings,
        private OrderNotificationService $notifications,
    ) {
    }

    /**
     * Cancel the order if its status is still in $allowedStatuses.
     *
     * Returns false (and changes nothing) when it is not, e.g. the
     * restaurant accepted in the meantime. System cancels also never
     * touch an order that has been paid. $order is refreshed either way,
     * so callers can read its current status.
     */
    public function cancel(
        RestaurantOrder $order,
        string $by,
        string $reason,
        array $allowedStatuses = self::PENDING_ONLY
    ): bool {
        $riderId = null;

        $cancelled = DB::transaction(function () use ($order, $by, $reason, $allowedStatuses, &$riderId) {
            // Lock payments before the order, same as the PayU success path
            $payments = RestaurantOrderPayment::where('order_id', $order->id)
                ->lockForUpdate()
                ->get();

            $locked = RestaurantOrder::whereKey($order->id)->lockForUpdate()->first();

            if (!$locked || !in_array($locked->order_status, $allowedStatuses, true)) {
                return false;
            }

            $isPaid = $locked->payment_status === 'paid'
                || $payments->contains(fn ($payment) => strtoupper((string) $payment->payment_status) === 'PAID');

            // Auto-expiry is only for unpaid orders; a callback may have just confirmed it
            if ($by === self::BY_SYSTEM && $isPaid) {
                return false;
            }

            $locked->update([
                'order_status'  => 'cancelled',
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
                'cancelled_by'  => $by,
            ]);

            if ($isPaid) {
                // Refunds are not automated yet
                Log::warning('Paid order cancelled, manual refund needed', [
                    'order_id'     => $locked->id,
                    'order_number' => $locked->order_number,
                    'cancelled_by' => $by,
                ]);
            } else {
                $locked->update(['payment_status' => 'failed']);

                RestaurantOrderPayment::where('order_id', $locked->id)
                    ->where('payment_status', 'PENDING')
                    ->update(['payment_status' => 'FAILED']);
            }

            OrderStatusHistory::create([
                'order_id'   => $locked->id,
                'status'     => 'cancelled',
                'changed_by' => $by,
                'remarks'    => $this->historyRemarks($by, $reason),
            ]);

            // Lock order everywhere: payments -> restaurant order -> delivery order -> rider
            $deliveryOrder = DeliveryOrder::where('order_number', $locked->order_number)
                ->whereNotIn('status', ['delivered', 'cancelled'])
                ->lockForUpdate()
                ->first();

            if ($deliveryOrder) {
                $riderId = $deliveryOrder->rider_id;

                $deliveryOrder->update(['status' => 'cancelled']);
            }

            $riderId = $riderId ?: $locked->rider_id;

            // Drop the pending earning (or take back a credited one)
            $this->earnings->reverse($locked, $reason);

            return true;
        });

        $order->refresh();

        if ($cancelled) {
            $this->notify($order, $by, $reason, $riderId ? (int) $riderId : null);
        }

        return $cancelled;
    }

    private function historyRemarks(string $by, string $reason): string
    {
        return match ($by) {
            self::BY_CUSTOMER   => 'Order cancelled by customer. Reason: ' . $reason,
            self::BY_RESTAURANT => 'Order rejected by restaurant. Reason: ' . $reason,
            self::BY_ADMIN      => 'Order cancelled by admin. Reason: ' . $reason,
            default             => 'Auto-cancelled. Reason: ' . $reason,
        };
    }

    private function notify(RestaurantOrder $order, string $by, string $reason, ?int $riderId): void
    {
        match ($by) {
            self::BY_CUSTOMER   => $this->notifications->orderCancelledByCustomer($order, $reason),
            self::BY_RESTAURANT => $this->notifications->orderRejectedByRestaurant($order, $reason),
            self::BY_ADMIN      => $this->notifications->orderCancelledByAdmin($order, $reason),
            default             => $this->notifications->orderExpiredUnpaid($order),
        };

        if ($riderId) {
            $this->notifications->orderCancelledForRider($order, $riderId, $reason);
        }
    }
}
