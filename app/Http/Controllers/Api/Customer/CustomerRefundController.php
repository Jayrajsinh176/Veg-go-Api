<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\OrderRefund;
use App\Models\RestaurantOrder;
use App\Services\Refund\RefundService;
use Illuminate\Http\Request;

class CustomerRefundController extends Controller
{
    public function __construct(
        private RefundService $refundService
    ) {
    }

    /**
     * Submit a post-delivery complaint.
     * Photo proof is mandatory.
     */
    public function store(Request $request, $orderId)
    {
        $validated = $request->validate([
            'reason'      => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'image'       => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'], // Mandatory proof image
        ], [
            'image.required' => 'Photo proof of the affected item is mandatory to submit a complaint.',
            'image.image'    => 'The proof must be a valid image file (JPEG, PNG, JPG, or WEBP).',
            'image.max'      => 'The image size may not be greater than 5MB.',
        ]);

        $customer = $request->user();

        $order = RestaurantOrder::where('id', $orderId)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        try {
            $refund = $this->refundService->submitPostDeliveryComplaint(
                $order,
                $customer,
                $validated['reason'],
                $validated['description'],
                $request->file('image')
            );

            return response()->json([
                'success' => true,
                'message' => 'Complaint submitted successfully. Our team will review your proof.',
                'data' => [
                    'complaint' => $this->transformComplaint($refund),
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Fetch complaint status for a specific order.
     */
    public function show(Request $request, $orderId)
    {
        $customer = $request->user();

        $refund = OrderRefund::where('order_id', $orderId)
            ->where('customer_id', $customer->id)
            ->with(['coupon'])
            ->latest()
            ->first();

        if (!$refund) {
            return response()->json([
                'success' => false,
                'message' => 'No refund or complaint found for this order.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'complaint' => $this->transformComplaint($refund),
            ],
        ], 200);
    }

    /**
     * List all refunds and complaints for the customer.
     */
    public function index(Request $request)
    {
        $customer = $request->user();

        $refunds = OrderRefund::where('customer_id', $customer->id)
            ->with(['order.restaurant', 'coupon'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Refunds fetched successfully.',
            'data' => [
                'refunds' => $refunds->map(fn ($r) => $this->transformComplaint($r)),
            ],
        ], 200);
    }

    private function transformComplaint(OrderRefund $refund): array
    {
        return [
            'id'                       => $refund->id,
            'refund_number'            => $refund->refund_number,
            'order_id'                 => $refund->order_id,
            'order_number'             => $refund->order?->order_number,
            'restaurant_name'          => $refund->restaurant?->restaurant_name,
            'scenario'                 => $refund->scenario,
            'refund_method'            => $refund->refund_method,
            'subtotal'                 => (float) $refund->subtotal,
            'refund_percentage'        => (float) $refund->refund_percentage,
            'refund_amount'            => (float) $refund->amount,
            'reason'                   => $refund->reason,
            'customer_note'            => $refund->customer_note,
            'proof_image_url'          => $refund->proof_image_url,
            'status'                   => $refund->status,
            'rejection_reason'         => $refund->rejection_reason,
            'coupon_code'              => $refund->coupon?->code,
            'coupon_discount'          => $refund->coupon ? (float) $refund->coupon->discount_value : null,
            'coupon_expires_at'        => $refund->coupon?->expires_at?->toIso8601String(),
            'created_at'               => $refund->created_at?->toIso8601String(),
            'reviewed_at'              => $refund->reviewed_at?->toIso8601String(),
        ];
    }
}

