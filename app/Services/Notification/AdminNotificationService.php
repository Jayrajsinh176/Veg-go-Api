<?php

namespace App\Services\Notification;

use App\Models\AdminNotification;
use App\Models\CustomerSupportTicket;
use App\Models\OrderRefund;
use App\Models\Restaurant;
use App\Models\RestaurantOrder;
use App\Models\RestaurantSupportTicket;
use App\Models\Rider;
use App\Models\RiderSupportTicket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates Admin panel notifications. Every method swallows its own
 * errors: a failed notification must never break the action that
 * triggered it (an order cancel, a payment callback, ...).
 */
class AdminNotificationService
{
    // Same type + record inside this window is created once (double submits, retries)
    private const DEDUPE_SECONDS = 300;

    public function notify(
        string $type,
        string $title,
        string $message,
        ?string $recordType = null,
        ?int $recordId = null,
        ?string $recordRef = null,
        array $meta = []
    ): ?AdminNotification {
        try {
            if (!array_key_exists($type, AdminNotification::TYPES)) {
                return null;
            }

            if ($recordType && $recordId) {
                $duplicate = AdminNotification::query()
                    ->where('type', $type)
                    ->where('record_type', $recordType)
                    ->where('record_id', $recordId)
                    ->where('created_at', '>', now()->subSeconds(self::DEDUPE_SECONDS))
                    ->exists();

                if ($duplicate) {
                    return null;
                }
            }

            return AdminNotification::query()->create([
                'type' => $type,
                'title' => Str::limit($title, 250),
                'message' => $message,
                'module' => AdminNotification::TYPES[$type]['module'],
                'record_type' => $recordType,
                'record_id' => $recordId,
                'record_ref' => $recordRef ? Str::limit($recordRef, 95) : null,
                'meta' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin notification could not be created', [
                'type' => $type,
                'record_type' => $recordType,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    public function restaurantApplicationSubmitted(Restaurant $restaurant): void
    {
        $name = $restaurant->restaurant_name ?: 'Restaurant #' . $restaurant->id;

        $this->notify(
            AdminNotification::TYPE_RESTAURANT_APPLICATION,
            "New Restaurant Application: {$name}",
            "{$name}" . ($restaurant->owner_name ? " (owner {$restaurant->owner_name})" : '')
                . ' submitted onboarding and is waiting for document review and approval.',
            'restaurant',
            $restaurant->id,
            $name
        );
    }

    public function riderApplicationSubmitted(Rider $rider): void
    {
        $name = trim((string) ($rider->name ?? '')) ?: 'Rider #' . $rider->id;

        $this->notify(
            AdminNotification::TYPE_RIDER_APPLICATION,
            "Delivery Partner Application: {$name}",
            "{$name} completed profile and KYC documents and is waiting for verification.",
            'rider',
            $rider->id,
            $name
        );
    }

    public function supportTicketCreated(CustomerSupportTicket|RestaurantSupportTicket|RiderSupportTicket $ticket, string $from, ?string $fromName = null): void
    {
        $who = $fromName ? ucfirst($from) . " {$fromName}" : ucfirst($from);

        $this->notify(
            AdminNotification::TYPE_SUPPORT_REQUEST,
            "Support Request: {$ticket->subject}",
            "{$who} raised a {$ticket->category} ticket: " . Str::limit((string) $ticket->description, 180),
            'support_ticket',
            $ticket->id,
            $ticket->ticket_number,
            ['from' => $from]
        );
    }

    public function complaintSubmitted(OrderRefund $refund, RestaurantOrder $order): void
    {
        $this->notify(
            AdminNotification::TYPE_ORDER_ISSUE,
            "Order Complaint: #{$order->order_number}",
            "Customer reported \"{$refund->reason}\" on a delivered order. Refund of ₹"
                . number_format((float) $refund->amount, 2) . ' is waiting for review.',
            'order_refund',
            $refund->id,
            $refund->refund_number ?: $order->order_number,
            ['order_id' => $order->id]
        );
    }

    public function orderCancelled(RestaurantOrder $order, string $by, string $reason): void
    {
        $paid = $order->payment_status === 'paid' || $order->payment_status === 'refunded';

        $this->notify(
            AdminNotification::TYPE_CANCELLATION,
            ($by === 'restaurant' ? 'Order Rejected by Restaurant' : 'Order Cancelled by Customer') . ": #{$order->order_number}",
            "Reason: {$reason}." . ($paid ? ' The order was paid online, so a refund was started.' : ''),
            'order',
            $order->id,
            $order->order_number,
            ['by' => $by]
        );
    }

    public function paymentFailed(RestaurantOrder $order, string $txnid): void
    {
        $this->notify(
            AdminNotification::TYPE_PAYMENT_ISSUE,
            "Payment Failed: #{$order->order_number}",
            "PayU reported a failed payment of ₹" . number_format((float) $order->total_amount, 2)
                . " (txn {$txnid}).",
            'order',
            $order->id,
            $order->order_number,
            ['txnid' => $txnid]
        );
    }

    public function paymentAfterCancellation(RestaurantOrder $order, string $txnid): void
    {
        $this->notify(
            AdminNotification::TYPE_PAYMENT_ISSUE,
            "Refund Needed: #{$order->order_number}",
            "Payment was received after the order was cancelled (PayU txn {$txnid}). Refund the customer.",
            'order',
            $order->id,
            $order->order_number,
            ['txnid' => $txnid]
        );
    }

    public function broadcastSent(string $title, string $audience, ?string $code = null): void
    {
        $this->notify(
            AdminNotification::TYPE_SYSTEM,
            "Broadcast Sent: {$title}",
            "Super Admin sent a broadcast to {$audience}.",
            'broadcast',
            null,
            $code
        );
    }
}
