<?php

namespace App\Services\Notification;

use App\Models\CustomerNotification;
use App\Models\DeliveryOrder;
use App\Models\RestaurantNotification;
use App\Models\RestaurantOrder;
use App\Models\Rider;
use App\Models\RiderNotification;
use Illuminate\Support\Facades\Log;

class OrderNotificationService
{
    /**
     * Notify customer about an order update.
     */
    public function notifyCustomer(
        int $customerId,
        string $title,
        string $message,
        ?int $orderId = null,
        string $type = 'order'
    ): ?CustomerNotification {
        try {
            return CustomerNotification::create([
                'customer_id' => $customerId,
                'title'       => $title,
                'message'     => $message,
                'type'        => $type,
                'order_id'    => $orderId,
                'is_read'     => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create customer notification', [
                'customer_id' => $customerId,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Notify restaurant about an order update.
     */
    public function notifyRestaurant(
        int $restaurantId,
        string $title,
        string $message,
        ?int $orderId = null,
        string $type = 'order'
    ): ?RestaurantNotification {
        try {
            return RestaurantNotification::create([
                'restaurant_id'  => $restaurantId,
                'type'           => $type,
                'title'          => $title,
                'message'        => $message,
                'reference_type' => 'order',
                'reference_id'   => $orderId,
                'is_read'        => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create restaurant notification', [
                'restaurant_id' => $restaurantId,
                'error'         => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Notify rider about an order update.
     */
    public function notifyRider(
        int $riderId,
        string $title,
        string $message,
        string $type = 'order'
    ): ?RiderNotification {
        try {
            return RiderNotification::create([
                'rider_id' => $riderId,
                'title'    => $title,
                'message'  => $message,
                'type'     => $type,
                'is_read'  => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create rider notification', [
                'rider_id' => $riderId,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Order Placed: notify restaurant and customer.
     */
    public function orderPlaced(RestaurantOrder $order): void
    {
        $restaurantName = $order->restaurant?->restaurant_name ?? 'the restaurant';

        // Notify Restaurant
        $this->notifyRestaurant(
            $order->restaurant_id,
            'New Order Received! 🔔',
            "New order #{$order->order_number} for ₹{$order->total_amount} has been placed. Please accept and prepare.",
            $order->id
        );

        // Notify Customer
        $this->notifyCustomer(
            $order->customer_id,
            'Order Placed Successfully! 🎉',
            "Your order #{$order->order_number} with {$restaurantName} has been placed.",
            $order->id
        );
    }

    /**
     * Order Accepted: notify customer and broadcast to nearby riders.
     */
    public function orderAccepted(RestaurantOrder $order, ?DeliveryOrder $deliveryOrder = null): void
    {
        $restaurantName = $order->restaurant?->restaurant_name ?? 'Restaurant';

        // Notify Customer
        $this->notifyCustomer(
            $order->customer_id,
            'Order Accepted & Preparing 🍳',
            "{$restaurantName} accepted order #{$order->order_number} and is preparing your food.",
            $order->id
        );

        // Notify Nearby Online Riders
        if ($deliveryOrder) {
            $this->notifyNearbyRiders($deliveryOrder);
        }
    }

    /**
     * Order Status Updated to Ready for Pickup: notify customer and assigned rider.
     */
    public function orderReadyForPickup(RestaurantOrder $order): void
    {
        $restaurantName = $order->restaurant?->restaurant_name ?? 'Restaurant';

        // Notify Customer
        $this->notifyCustomer(
            $order->customer_id,
            'Food is Ready! 🍲',
            "Your food from {$restaurantName} is packed and ready for delivery.",
            $order->id
        );

        // Notify Assigned Rider (if any)
        if ($order->rider_id) {
            $this->notifyRider(
                $order->rider_id,
                'Order Ready for Pickup 📦',
                "Order #{$order->order_number} is ready at {$restaurantName}. Collect with Pickup OTP: {$order->pickup_otp}."
            );
        }
    }

    /**
     * Rider Accepted / Assigned: notify customer and restaurant.
     */
    public function riderAssigned(DeliveryOrder $deliveryOrder, ?RestaurantOrder $restaurantOrder = null, ?Rider $rider = null): void
    {
        if (!$restaurantOrder) {
            $restaurantOrder = RestaurantOrder::where('order_number', $deliveryOrder->order_number)->first();
        }

        $riderName = $rider?->name ?? 'A delivery partner';

        if ($restaurantOrder) {
            // Notify Customer
            $this->notifyCustomer(
                $restaurantOrder->customer_id,
                'Delivery Partner Assigned 🛵',
                "{$riderName} has been assigned to deliver your order #{$deliveryOrder->order_number}.",
                $restaurantOrder->id
            );

            // Notify Restaurant
            $this->notifyRestaurant(
                $restaurantOrder->restaurant_id,
                'Delivery Partner Assigned 🛵',
                "{$riderName} accepted Order #{$deliveryOrder->order_number} and is heading to your store.",
                $restaurantOrder->id
            );
        }
    }

    /**
     * Rider Arrived at Restaurant: notify restaurant.
     */
    public function riderArrived(DeliveryOrder $deliveryOrder, ?RestaurantOrder $restaurantOrder = null, ?Rider $rider = null): void
    {
        $riderName = $rider?->name ?? 'Delivery partner';
        $restaurantId = $restaurantOrder?->restaurant_id;

        if (!$restaurantId && $restaurantOrder === null) {
            $restaurantOrder = RestaurantOrder::where('order_number', $deliveryOrder->order_number)->first();
            $restaurantId = $restaurantOrder?->restaurant_id;
        }

        if ($restaurantId) {
            $pickupOtp = $restaurantOrder?->pickup_otp ?? $deliveryOrder->pickup_otp;
            $otpText = $pickupOtp ? " Pickup OTP: {$pickupOtp}." : '';

            $this->notifyRestaurant(
                $restaurantId,
                'Delivery Partner Arrived 🛵',
                "{$riderName} has arrived to pick up Order #{$deliveryOrder->order_number}.{$otpText}",
                $restaurantOrder?->id
            );
        }
    }

    /**
     * Order Picked Up: notify customer (with delivery OTP) and restaurant.
     */
    public function orderPickedUp(DeliveryOrder $deliveryOrder, ?RestaurantOrder $restaurantOrder = null, ?Rider $rider = null): void
    {
        if (!$restaurantOrder) {
            $restaurantOrder = RestaurantOrder::where('order_number', $deliveryOrder->order_number)->first();
        }

        $riderName = $rider?->name ?? 'Delivery partner';

        if ($restaurantOrder) {
            $otpText = $restaurantOrder->delivery_otp
                ? " Share Delivery OTP {$restaurantOrder->delivery_otp} when your food arrives."
                : '';

            // Notify Customer
            $this->notifyCustomer(
                $restaurantOrder->customer_id,
                'Order Out for Delivery! 🚀',
                "{$riderName} has picked up Order #{$deliveryOrder->order_number} and is heading your way.{$otpText}",
                $restaurantOrder->id
            );

            // Notify Restaurant
            $this->notifyRestaurant(
                $restaurantOrder->restaurant_id,
                'Order Dispatched 📦',
                "Order #{$deliveryOrder->order_number} has been picked up by {$riderName}.",
                $restaurantOrder->id
            );
        }
    }

    /**
     * Order Delivered: notify customer, restaurant and rider.
     */
    public function orderDelivered(DeliveryOrder $deliveryOrder, ?RestaurantOrder $restaurantOrder = null, ?Rider $rider = null): void
    {
        if (!$restaurantOrder) {
            $restaurantOrder = RestaurantOrder::where('order_number', $deliveryOrder->order_number)->first();
        }

        if ($restaurantOrder) {
            // Notify Customer
            $this->notifyCustomer(
                $restaurantOrder->customer_id,
                'Order Delivered! Enjoy your meal 😋',
                "Order #{$deliveryOrder->order_number} has been delivered. Please rate your food and service!",
                $restaurantOrder->id
            );

            // Notify Restaurant
            $this->notifyRestaurant(
                $restaurantOrder->restaurant_id,
                'Order Delivered Successfully ✅',
                "Order #{$deliveryOrder->order_number} has been delivered. Earnings have been credited to your wallet.",
                $restaurantOrder->id
            );
        }

        // Notify Rider
        if ($rider || $deliveryOrder->rider_id) {
            $riderId = $rider?->id ?? $deliveryOrder->rider_id;
            $earning = $deliveryOrder->rider_earning;

            $this->notifyRider(
                $riderId,
                'Delivery Completed! 💰',
                "Order #{$deliveryOrder->order_number} completed! ₹{$earning} credited to your wallet."
            );
        }
    }

    /**
     * Order Cancelled by Customer: notify restaurant.
     */
    public function orderCancelledByCustomer(RestaurantOrder $order, string $reason): void
    {
        $this->notifyRestaurant(
            $order->restaurant_id,
            'Order Cancelled by Customer ⚠️',
            "Order #{$order->order_number} was cancelled by the customer. Reason: {$reason}.",
            $order->id
        );
    }

    /**
     * Order Rejected by Restaurant: notify customer.
     */
    public function orderRejectedByRestaurant(RestaurantOrder $order, string $reason): void
    {
        $restaurantName = $order->restaurant?->restaurant_name ?? 'Restaurant';

        $this->notifyCustomer(
            $order->customer_id,
            'Order Cancelled by Restaurant ❌',
            "{$restaurantName} could not accept your order #{$order->order_number}. Reason: {$reason}.",
            $order->id
        );
    }

    /**
     * Order Cancelled by Admin: notify customer and restaurant.
     */
    public function orderCancelledByAdmin(RestaurantOrder $order, string $reason): void
    {
        $this->notifyCustomer(
            $order->customer_id,
            'Order Cancelled ❌',
            "Your order #{$order->order_number} has been cancelled by VegGo support. Reason: {$reason}.",
            $order->id
        );

        $this->notifyRestaurant(
            $order->restaurant_id,
            'Order Cancelled by Admin ⚠️',
            "Order #{$order->order_number} was cancelled by VegGo admin. Reason: {$reason}.",
            $order->id
        );
    }

    /**
     * Unpaid online order auto-cancelled: notify customer.
     */
    public function orderExpiredUnpaid(RestaurantOrder $order): void
    {
        $this->notifyCustomer(
            $order->customer_id,
            'Payment Not Completed ⏱️',
            "Your order #{$order->order_number} was cancelled because the online payment was not completed in time.",
            $order->id
        );
    }

    /**
     * Online payment confirmed after the order was cancelled: notify customer.
     */
    public function paymentReceivedForCancelledOrder(RestaurantOrder $order): void
    {
        $this->notifyCustomer(
            $order->customer_id,
            'Refund Pending 💳',
            "We received your payment for order #{$order->order_number}, but the order was already cancelled. Your amount will be refunded.",
            $order->id
        );
    }

    /**
     * Order cancelled while a rider was assigned: notify that rider.
     */
    public function orderCancelledForRider(RestaurantOrder $order, int $riderId, string $reason): void
    {
        $this->notifyRider(
            $riderId,
            'Order Cancelled ❌',
            "Order #{$order->order_number} has been cancelled. Reason: {$reason}. You do not need to pick it up."
        );
    }

    /**
     * Rider Unassigned / Cancelled Assignment: notify restaurant.
     */
    public function riderUnassigned(DeliveryOrder $deliveryOrder, ?RestaurantOrder $restaurantOrder = null, ?Rider $rider = null, string $reason = ''): void
    {
        if (!$restaurantOrder) {
            $restaurantOrder = RestaurantOrder::where('order_number', $deliveryOrder->order_number)->first();
        }

        if ($restaurantOrder) {
            $riderName = $rider?->name ?? 'Delivery partner';

            $this->notifyRestaurant(
                $restaurantOrder->restaurant_id,
                'Delivery Partner Reassignment 🛵',
                "{$riderName} was unassigned from Order #{$deliveryOrder->order_number} ({$reason}). The order is now available for other delivery partners.",
                $restaurantOrder->id
            );
        }
    }

    /**
     * Customer Reviewed Order: notify restaurant.
     */
    public function orderReviewed(RestaurantOrder $order, int $rating): void
    {
        $stars = str_repeat('⭐', max(1, min(5, $rating)));

        $this->notifyRestaurant(
            $order->restaurant_id,
            "New Customer Review {$stars}",
            "A customer gave a {$rating}-star rating for Order #{$order->order_number}.",
            $order->id,
            'review'
        );
    }

    /**
     * Notify nearby online riders when a new delivery order is available.
     */
    protected function notifyNearbyRiders(DeliveryOrder $deliveryOrder, float $radiusKm = 10): void
    {
        if ($deliveryOrder->pickup_latitude === null || $deliveryOrder->pickup_longitude === null) {
            return;
        }

        try {
            $lat = (float) $deliveryOrder->pickup_latitude;
            $lng = (float) $deliveryOrder->pickup_longitude;

            // Find online riders within radius
            $nearbyRiders = Rider::query()
                ->where('status', 'approved')
                ->where('is_online', true)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->get()
                ->filter(function ($rider) use ($lat, $lng, $radiusKm) {
                    $dist = $this->calculateDistance(
                        $lat,
                        $lng,
                        (float) $rider->latitude,
                        (float) $rider->longitude
                    );

                    return $dist !== null && $dist <= $radiusKm;
                });

            foreach ($nearbyRiders as $rider) {
                $this->notifyRider(
                    $rider->id,
                    'New Order Available Nearby 🛵',
                    "Order #{$deliveryOrder->order_number} from {$deliveryOrder->restaurant_name} is available for delivery. Earning: ₹{$deliveryOrder->rider_earning}."
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify nearby riders', [
                'order_number' => $deliveryOrder->order_number,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Distance calculation helper.
     */
    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): ?float
    {
        $earthRadius = 6371;

        $latDiff = deg2rad($lat2 - $lat1);
        $lonDiff = deg2rad($lon2 - $lon1);

        $a = sin($latDiff / 2) * sin($latDiff / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lonDiff / 2) * sin($lonDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }
}
