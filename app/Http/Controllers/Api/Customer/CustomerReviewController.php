<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\RestaurantOrder;
use App\Models\RestaurantReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerReviewController extends Controller
{
    /**
     * Submit a rating and review for a delivered order.
     */
    public function store(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
        ], [
            'rating.required' => 'Please provide a rating between 1 and 5 stars.',
            'rating.min'      => 'Rating must be at least 1 star.',
            'rating.max'      => 'Rating cannot exceed 5 stars.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $customer = $request->user();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $order = RestaurantOrder::query()
            ->with('restaurant')
            ->where('id', $orderId)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        // Only delivered orders can be reviewed
        if ($order->order_status !== 'delivered') {
            return response()->json([
                'success' => false,
                'message' => 'You can only rate and review orders that have been delivered.',
                'current_status' => $order->order_status,
            ], 422);
        }

        // Check if this order was already reviewed
        $existing = RestaurantReview::where('restaurant_order_id', $order->id)->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this order.',
                'data' => [
                    'id'         => $existing->id,
                    'rating'     => (int) $existing->rating,
                    'review'     => $existing->review,
                    'created_at' => $existing->created_at,
                ],
            ], 409);
        }

        $review = RestaurantReview::create([
            'restaurant_id'       => $order->restaurant_id,
            'restaurant_order_id' => $order->id,
            'customer_id'         => $customer->id,
            'rating'              => (int) $request->rating,
            'review'              => $request->filled('review') ? trim((string) $request->review) : null,
        ]);

        // Notify restaurant about the new review
        app(\App\Services\Notification\OrderNotificationService::class)->orderReviewed($order, (int) $request->rating);

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully. Thank you for your feedback!',
            'data' => [
                'id'           => $review->id,
                'order_id'     => $order->id,
                'order_number' => $order->order_number,
                'restaurant'   => [
                    'id'   => $order->restaurant?->id,
                    'name' => $order->restaurant?->restaurant_name,
                ],
                'rating'       => (int) $review->rating,
                'review'       => $review->review,
                'created_at'   => $review->created_at,
            ],
        ], 201);
    }

    /**
     * Get review details for a specific order.
     * Easy helper for the frontend to decide whether to show "Rate Order" or existing rating.
     */
    public function show(Request $request, $orderId)
    {
        $customer = $request->user();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $order = RestaurantOrder::query()
            ->where('id', $orderId)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $review = RestaurantReview::with('reply')
            ->where('restaurant_order_id', $order->id)
            ->first();

        if (!$review) {
            return response()->json([
                'success'      => true,
                'has_reviewed' => false,
                'can_review'   => $order->order_status === 'delivered',
                'message'      => 'No review submitted for this order yet.',
            ], 200);
        }

        return response()->json([
            'success'      => true,
            'has_reviewed' => true,
            'can_review'   => false,
            'data' => [
                'id'               => $review->id,
                'order_id'         => $order->id,
                'rating'           => (int) $review->rating,
                'review'           => $review->review,
                'created_at'       => $review->created_at,
                'restaurant_reply' => $review->reply ? [
                    'reply'      => $review->reply->reply,
                    'replied_at' => optional($review->reply->created_at)->diffForHumans(),
                ] : null,
            ],
        ], 200);
    }

    /**
     * Get all reviews submitted by the authenticated customer.
     */
    public function index(Request $request)
    {
        $customer = $request->user();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $reviews = RestaurantReview::query()
            ->with([
                'restaurant:id,restaurant_name',
                'order:id,order_number,total_amount,delivered_at',
                'reply',
            ])
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Reviews fetched successfully.',
            'count'   => $reviews->count(),
            'data'    => $reviews->map(function ($rev) {
                return [
                    'id'           => $rev->id,
                    'order_id'     => $rev->restaurant_order_id,
                    'order_number' => $rev->order?->order_number,
                    'restaurant'   => [
                        'id'   => $rev->restaurant?->id,
                        'name' => $rev->restaurant?->restaurant_name,
                    ],
                    'rating'       => (int) $rev->rating,
                    'review'       => $rev->review,
                    'created_at'   => $rev->created_at,
                    'restaurant_reply' => $rev->reply ? [
                        'reply'      => $rev->reply->reply,
                        'replied_at' => optional($rev->reply->created_at)->diffForHumans(),
                    ] : null,
                ];
            })->values(),
        ], 200);
    }
}
