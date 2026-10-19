<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\OrderRefund;
use App\Services\Refund\RefundService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SuperAdminRefundController extends Controller
{
    public function __construct(
        private RefundService $refundService
    ) {
    }

    /**
     * List all refunds / complaints for the Refund Command Center.
     */
    public function index(Request $request)
    {
        $query = OrderRefund::with([
            'order.items',
            'order.payment',
            'customer',
            'restaurant',
            'coupon',
            'reviewer',
        ])->latest('id');

        // Status filter
        if ($request->filled('status') && $request->status !== 'All') {
            $statusMap = [
                'Under Review' => OrderRefund::STATUS_UNDER_REVIEW,
                'Processing'   => OrderRefund::STATUS_PROCESSING,
                'Completed'    => OrderRefund::STATUS_COMPLETED,
                'Rejected'     => OrderRefund::STATUS_REJECTED,
            ];
            $dbStatus = $statusMap[$request->status] ?? strtolower(str_replace(' ', '_', $request->status));
            $query->where('status', $dbStatus);
        }

        // Date filter
        if ($request->filled('date') && $request->date !== 'All') {
            match ($request->date) {
                'Today' => $query->whereDate('created_at', today()),
                'Yesterday' => $query->whereDate('created_at', today()->subDay()),
                'This Week' => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
                default => null,
            };
        }

        // Search query
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('refund_number', 'LIKE', "%{$search}%")
                    ->orWhereHas('order', fn ($oq) => $oq->where('order_number', 'LIKE', "%{$search}%"))
                    ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'LIKE', "%{$search}%")->orWhere('phone', 'LIKE', "%{$search}%"))
                    ->orWhereHas('restaurant', fn ($rq) => $rq->where('restaurant_name', 'LIKE', "%{$search}%"));
            });
        }

        $allRefunds = $query->get();

        // Calculate summary counts
        $counts = [
            'total'       => OrderRefund::count(),
            'underReview' => OrderRefund::where('status', OrderRefund::STATUS_UNDER_REVIEW)->count(),
            'processing'  => OrderRefund::where('status', OrderRefund::STATUS_PROCESSING)->count(),
            'completed'   => OrderRefund::where('status', OrderRefund::STATUS_COMPLETED)->count(),
            'rejected'    => OrderRefund::where('status', OrderRefund::STATUS_REJECTED)->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Refunds fetched successfully.',
            'data'    => [
                'counts'  => $counts,
                'refunds' => $allRefunds->map(fn ($r) => $this->transformForAdmin($r))->values(),
            ],
        ]);
    }

    /**
     * Show single refund details.
     */
    public function show($id)
    {
        $refund = OrderRefund::with([
            'order.items',
            'order.payment',
            'customer',
            'restaurant',
            'coupon',
            'reviewer',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'refund' => $this->transformForAdmin($refund),
            ],
        ]);
    }

    /**
     * Approve a customer complaint:
     * - Generates 20% food subtotal Coupon for customer
     * - Debits restaurant weekly settlement ledger
     * - Marks completed
     */
    public function approve(Request $request, $id)
    {
        $refund = OrderRefund::findOrFail($id);
        $admin = $request->user();

        try {
            $approved = $this->refundService->approveComplaint($refund, $admin?->id);

            return response()->json([
                'success' => true,
                'message' => "Refund #{$approved->refund_number} approved successfully. Coupon generated and restaurant settlement debited.",
                'data'    => [
                    'refund' => $this->transformForAdmin($approved),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reject a customer complaint with reason.
     */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $refund = OrderRefund::findOrFail($id);
        $admin = $request->user();

        try {
            $rejected = $this->refundService->rejectComplaint($refund, $validated['rejection_reason'], $admin?->id);

            return response()->json([
                'success' => true,
                'message' => "Refund #{$rejected->refund_number} rejected.",
                'data'    => [
                    'refund' => $this->transformForAdmin($rejected),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function transformForAdmin(OrderRefund $refund): array
    {
        $order = $refund->order;
        $customer = $refund->customer;
        $restaurant = $refund->restaurant;
        $payment = $order?->payment;

        $statusLabel = match ($refund->status) {
            OrderRefund::STATUS_UNDER_REVIEW => 'Under Review',
            OrderRefund::STATUS_PROCESSING   => 'Processing',
            OrderRefund::STATUS_COMPLETED    => 'Completed',
            OrderRefund::STATUS_REJECTED     => 'Rejected',
            default                          => ucfirst($refund->status),
        };

        $typeLabel = match ($refund->scenario) {
            OrderRefund::SCENARIO_POST_DELIVERY_COMPLAINT => '20% Coupon',
            default                                       => 'Full',
        };

        $dateCategory = 'Older';
        if ($refund->created_at?->isToday()) {
            $dateCategory = 'Today';
        } elseif ($refund->created_at?->isYesterday()) {
            $dateCategory = 'Yesterday';
        } elseif ($refund->created_at?->isCurrentWeek()) {
            $dateCategory = 'This Week';
        }

        return [
            'dbId'            => $refund->id,
            'id'              => $refund->refund_number,
            'orderId'         => $order?->order_number ?? "ORD-{$refund->order_id}",
            'customer'        => $customer?->name ?? 'Customer',
            'customerPhone'   => $customer?->phone ?? '—',
            'restaurant'      => $restaurant?->restaurant_name ?? '—',
            'amount'          => (float) $refund->amount,
            'originalAmount'  => (float) ($refund->subtotal ?: ($order?->subtotal ?: $order?->total_amount)),
            'type'            => $typeLabel,
            'reason'          => $refund->reason,
            'status'          => $statusLabel,
            'rawStatus'       => $refund->status,
            'scenario'        => $refund->scenario,
            'requestedAt'     => $refund->created_at?->format('M d, H:i') ?? '—',
            'date'            => $dateCategory,
            'paymentMethod'   => match (strtolower((string) ($payment?->payment_method ?? $order?->payment_method))) {
                'cash', 'cod' => 'Cash on Delivery',
                'online'      => 'Online / PayU',
                default       => 'UPI / Online',
            },
            'customerNote'    => $refund->customer_note ?? '—',
            'proofImageUrl'   => $refund->proof_image_url,
            'couponCode'      => $refund->coupon?->code,
            'rejectionReason' => $refund->rejection_reason,
            'reviewedBy'      => $refund->reviewer?->name,
            'reviewedAt'      => $refund->reviewed_at?->toIso8601String(),
        ];
    }
}

