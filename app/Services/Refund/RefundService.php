<?php

namespace App\Services\Refund;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\OrderRefund;
use App\Models\Restaurant;
use App\Models\RestaurantNotification;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderPayment;
use App\Services\Payment\PayUGateway;
use App\Services\Restaurant\RestaurantSettlementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RefundService
{
    public function __construct(
        private PayUGateway $payUGateway,
        private RestaurantSettlementService $settlementService,
    ) {
    }

    /**
     * Scenario 1: Order cancelled pre-delivery or due to rider issue.
     * Full refund to original online source account (PayU).
     */
    public function createPreDeliveryRefund(RestaurantOrder $order, string $reason): ?OrderRefund
    {
        return DB::transaction(function () use ($order, $reason) {
            // Check if already created
            $existing = OrderRefund::where('order_id', $order->id)
                ->where('scenario', OrderRefund::SCENARIO_PRE_DELIVERY_CANCEL)
                ->first();

            if ($existing) {
                return $existing;
            }

            $payment = RestaurantOrderPayment::where('order_id', $order->id)->first();
            $isPaidOnline = strtoupper((string) ($payment?->payment_status ?? $order->payment_status)) === 'PAID';

            if (!$isPaidOnline) {
                return null;
            }

            $refundNumber = $this->generateRefundNumber();
            $refundAmount = (float) $order->total_amount;
            $txnid = $payment?->transaction_id;

            $gatewayRefundId = null;
            $gatewayStatus = 'pending';
            $refundStatus = OrderRefund::STATUS_PROCESSING;

            // Attempt PayU refund if transaction_id exists
            if ($txnid) {
                try {
                    $gatewayResult = $this->payUGateway->refund($txnid, $refundAmount, $refundNumber);
                    $gatewayRefundId = $gatewayResult['refund_id'] ?? null;
                    $gatewayStatus = $gatewayResult['status'] ?? 'processing';

                    if (!empty($gatewayResult['success'])) {
                        $refundStatus = OrderRefund::STATUS_COMPLETED;
                    }
                } catch (\Throwable $e) {
                    Log::error('PayU refund call failed during order cancellation', [
                        'order_id' => $order->id,
                        'txnid' => $txnid,
                        'error' => $e->getMessage(),
                    ]);
                    $gatewayStatus = 'failed';
                }
            }

            $refund = OrderRefund::create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'restaurant_id' => $order->restaurant_id,
                'payment_id' => $payment?->id,
                'refund_number' => $refundNumber,
                'scenario' => OrderRefund::SCENARIO_PRE_DELIVERY_CANCEL,
                'refund_method' => OrderRefund::METHOD_SOURCE_ACCOUNT,
                'subtotal' => $order->subtotal ?? 0,
                'refund_percentage' => 100.00,
                'amount' => $refundAmount,
                'reason' => $reason,
                'customer_note' => 'Order cancelled pre-delivery. Automated refund to payment source.',
                'proof_image' => null,
                'gateway_refund_id' => $gatewayRefundId,
                'gateway_status' => $gatewayStatus,
                'status' => $refundStatus,
                'reviewed_at' => now(),
            ]);

            $order->update(['payment_status' => 'refunded']);
            $payment?->update(['payment_status' => 'REFUNDED']);

            // Notify Customer
            CustomerNotification::create([
                'customer_id' => $order->customer_id,
                'title' => 'Refund Initiated',
                'message' => "Order #{$order->order_number} was cancelled. A full refund of ₹" . number_format($refundAmount, 2) . " has been initiated to your original payment method.",
                'type' => 'refund',
                'order_id' => $order->id,
            ]);

            return $refund;
        });
    }

    /**
     * Scenario 2: Post-delivery customer complaint.
     * Photo proof is mandatory. 20% refund calculated strictly on Food Subtotal.
     */
    public function submitPostDeliveryComplaint(
        RestaurantOrder $order,
        Customer $customer,
        string $reason,
        string $description,
        UploadedFile $imageFile
    ): OrderRefund {
        if ($order->order_status !== 'delivered') {
            throw new \InvalidArgumentException('Complaints can only be filed after the order has been delivered.');
        }

        if ($order->customer_id !== $customer->id) {
            throw new \InvalidArgumentException('This order does not belong to your account.');
        }

        $existing = OrderRefund::where('order_id', $order->id)
            ->where('scenario', OrderRefund::SCENARIO_POST_DELIVERY_COMPLAINT)
            ->first();

        if ($existing) {
            throw new \InvalidArgumentException('A complaint has already been submitted for this order.');
        }

        // Mandatory image file upload
        $imagePath = $imageFile->store('complaints', 'public');

        // 20% on Food Subtotal ONLY (extra charges excluded)
        $subtotal = (float) $order->subtotal;
        $refundAmount = round(($subtotal * 20) / 100, 2);

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'restaurant_id' => $order->restaurant_id,
            'payment_id' => $order->payment?->id,
            'refund_number' => $this->generateRefundNumber(),
            'scenario' => OrderRefund::SCENARIO_POST_DELIVERY_COMPLAINT,
            'refund_method' => OrderRefund::METHOD_COUPON,
            'subtotal' => $subtotal,
            'refund_percentage' => 20.00,
            'amount' => $refundAmount,
            'reason' => $reason,
            'customer_note' => $description,
            'proof_image' => $imagePath,
            'status' => OrderRefund::STATUS_UNDER_REVIEW,
        ]);

        // Customer notification
        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Complaint Received',
            'message' => "Your complaint for order #{$order->order_number} has been received and is under review by our support team.",
            'type' => 'complaint',
            'order_id' => $order->id,
        ]);

        app(\App\Services\Notification\AdminNotificationService::class)->complaintSubmitted($refund, $order);

        return $refund;
    }

    /**
     * Admin approves complaint:
     * 1. Issues single-use coupon for customer (20% of food subtotal)
     * 2. Debits restaurant settlement wallet
     * 3. Marks status completed
     */
    public function approveComplaint(OrderRefund $refund, ?int $adminId = null): OrderRefund
    {
        if ($refund->status !== OrderRefund::STATUS_UNDER_REVIEW) {
            throw new \InvalidArgumentException('Only complaints currently under review can be approved.');
        }

        return DB::transaction(function () use ($refund, $adminId) {
            $order = $refund->order()->lockForUpdate()->firstOrFail();
            $restaurant = Restaurant::lockForUpdate()->findOrFail($refund->restaurant_id);

            // 1. Generate exclusive single-use Coupon for Customer
            $couponCode = 'COMP-' . strtoupper(Str::random(8));

            $coupon = Coupon::create([
                'customer_id' => $refund->customer_id,
                'order_id' => $refund->order_id,
                'code' => $couponCode,
                'discount_type' => 'fixed',
                'discount_value' => $refund->amount,
                'min_order_amount' => 0.00,
                'max_discount_amount' => $refund->amount,
                'is_single_use' => true,
                'is_compensation' => true,
                'is_active' => true,
                'starts_at' => now(),
                'expires_at' => now()->addDays(90),
            ]);

            // 2. Debit Restaurant Settlement Wallet
            $settlement = $this->settlementService->settlementFor($restaurant);

            $this->settlementService->record(
                $restaurant,
                'debit',
                (float) $refund->amount,
                'Customer Complaint Penalty — Order #' . $order->order_number,
                'Deduction of 20% food subtotal (₹' . number_format($refund->amount, 2) . ') for approved customer complaint (' . $refund->reason . ').',
                'order_complaint_deduction',
                $order->id,
                $settlement
            );

            // 3. Update Refund record
            $refund->update([
                'coupon_id' => $coupon->id,
                'status' => OrderRefund::STATUS_COMPLETED,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);

            // 4. Notify Customer
            CustomerNotification::create([
                'customer_id' => $refund->customer_id,
                'title' => 'Complaint Approved 🎉',
                'message' => "Your complaint for Order #{$order->order_number} was approved! Use coupon code {$couponCode} to get ₹" . number_format($refund->amount, 2) . " off on your next meal.",
                'type' => 'coupon',
                'order_id' => $order->id,
            ]);

            // 5. Notify Restaurant
            RestaurantNotification::create([
                'restaurant_id' => $restaurant->id,
                'type' => 'payment',
                'title' => 'Customer Complaint Deduction',
                'message' => "₹" . number_format($refund->amount, 2) . " was debited from your earnings for Order #{$order->order_number} due to customer complaint ({$refund->reason}).",
                'reference_type' => 'restaurant_order',
                'reference_id' => $order->id,
            ]);

            return $refund->fresh(['coupon', 'order', 'customer', 'restaurant']);
        });
    }

    /**
     * Admin rejects complaint with reason.
     */
    public function rejectComplaint(OrderRefund $refund, string $reason, ?int $adminId = null): OrderRefund
    {
        if ($refund->status !== OrderRefund::STATUS_UNDER_REVIEW) {
            throw new \InvalidArgumentException('Only complaints currently under review can be rejected.');
        }

        $refund->update([
            'status' => OrderRefund::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
        ]);

        $order = $refund->order;

        CustomerNotification::create([
            'customer_id' => $refund->customer_id,
            'title' => 'Complaint Update',
            'message' => "Your complaint for Order #{$order->order_number} could not be approved. Reason: {$reason}",
            'type' => 'complaint_rejected',
            'order_id' => $order->id,
        ]);

        return $refund->fresh();
    }

    private function generateRefundNumber(): string
    {
        $prefix = 'RF-' . now()->format('dm') . '-';

        $lastRefund = OrderRefund::where('refund_number', 'LIKE', "{$prefix}%")
            ->latest('id')
            ->first();

        $sequence = 1;
        if ($lastRefund && preg_match('/' . preg_quote($prefix, '/') . '(\d+)$/', $lastRefund->refund_number, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        do {
            $refundNumber = $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
            $sequence++;
        } while (
            OrderRefund::where('refund_number', $refundNumber)->exists()
        );

        return $refundNumber;
    }
}

