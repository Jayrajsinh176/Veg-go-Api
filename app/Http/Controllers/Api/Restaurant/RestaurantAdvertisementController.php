<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Exceptions\AdvertisementException;
use App\Http\Controllers\Controller;
use App\Models\RestaurantAdPackage;
use App\Models\RestaurantAdPayment;
use App\Models\RestaurantAdvertisement;
use App\Models\RestaurantCategory;
use App\Services\Restaurant\RestaurantAdvertisementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RestaurantAdvertisementController extends Controller
{
    public function __construct(private RestaurantAdvertisementService $service)
    {
    }

    /**
     * The 4 packages with their durations and prices (prices from DB).
     */
    public function packages()
    {
        return response()->json([
            'success' => true,
            'message' => 'Advertisement packages fetched successfully.',
            'data' => [
                'gst_percentage' => (float) config('advertisement.gst_percentage', 18),
                'packages' => $this->service->activePackages()
                    ->map(fn ($package) => $this->formatPackage($package))
                    ->values(),
            ],
        ]);
    }

    public function package($id)
    {
        $package = $this->service->findActivePackage((int) $id);

        return response()->json([
            'success' => true,
            'message' => 'Advertisement package fetched successfully.',
            'data' => [
                'gst_percentage' => (float) config('advertisement.gst_percentage', 18),
                'package' => $this->formatPackage($package),
            ],
        ]);
    }

    /**
     * Package 2 picker: this restaurant's active categories and the active
     * food items in them. Optional ?category_id=
     */
    public function foodItems(Request $request)
    {
        $restaurant = $request->user();

        $categories = RestaurantCategory::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->when($request->filled('category_id'), fn ($q) => $q->where('id', (int) $request->category_id))
            ->with(['menuItems' => fn ($q) => $q
                ->where('restaurant_id', $restaurant->id)
                ->where('is_active', true)
                ->orderBy('name')])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Food items fetched successfully.',
            'data' => [
                'categories' => $categories->map(fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'items' => $category->menuItems->map(fn ($item) => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'price' => $item->price,
                        'discount_price' => $item->discount_price,
                        'image' => $item->image,
                        'is_available' => (bool) $item->is_available,
                    ])->values(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Create a draft. restaurant_id, target, amounts and statuses are all
     * decided by the backend; anything else in the request is ignored.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|integer',
            'package_duration_id' => 'required|integer',
            'food_item_id' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $restaurant = $request->user();

        $advertisement = $this->service->create(
            $restaurant,
            $request->only(['package_id', 'package_duration_id', 'food_item_id']),
            $request->file('image'),
            RestaurantAdvertisement::CREATED_BY_RESTAURANT,
            $restaurant->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Advertisement created. Please complete the payment to make it live.',
            'data' => [
                'advertisement' => $this->formatAdvertisement($advertisement),
            ],
        ], 201);
    }

    /**
     * My Advertisements. Optional ?status=all|draft|pending|scheduled|active|paused|expired|cancelled
     */
    public function index(Request $request)
    {
        $advertisements = RestaurantAdvertisement::query()
            ->where('restaurant_id', $request->user()->id)
            ->with(['package', 'foodItem.category', 'restaurant'])
            ->latest('id')
            ->get()
            ->map(fn ($advertisement) => $this->formatAdvertisement($advertisement));

        $statuses = ['draft', 'pending', 'scheduled', 'active', 'paused', 'expired', 'cancelled'];

        $counts = ['all' => $advertisements->count()];
        foreach ($statuses as $status) {
            $counts[$status] = $advertisements->where('status', $status)->count();
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $advertisements = $advertisements->where('status', $request->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Advertisements fetched successfully.',
            'data' => [
                'counts' => $counts,
                'advertisements' => $advertisements->values(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $advertisement = $this->service->findForRestaurant($request->user(), (int) $id);

        return response()->json([
            'success' => true,
            'message' => 'Advertisement fetched successfully.',
            'data' => [
                'advertisement' => $this->formatAdvertisement($advertisement, true),
            ],
        ]);
    }

    /**
     * Pay Now: creates the gateway order the app opens checkout with.
     */
    public function payment(Request $request, $id)
    {
        $result = $this->service->initiatePayment($request->user(), (int) $id);

        // checkout is null while online payment is switched off (pay later)
        $paymentRequired = $result['checkout'] !== null;

        return response()->json([
            'success' => true,
            'message' => $paymentRequired
                ? 'Payment order created.'
                : 'Advertisement is now active. Payment will be collected later.',
            'data' => [
                'payment_required' => $paymentRequired,
                'advertisement' => $this->formatAdvertisement($result['advertisement']),
                'checkout' => $result['checkout'],
            ],
        ]);
    }

    /**
     * App sends the gateway checkout result; backend verifies it.
     */
    public function verifyPayment(Request $request)
    {
        $orderId = $request->input('order_id')
            ?? $request->input('txnid');
        $paymentId = $request->input('payment_id')
            ?? $request->input('mihpayid')
            ?? $orderId;
        $signature = $request->input('signature')
            ?? $request->input('hash')
            ?? '';

        if (empty($orderId)) {
            return response()->json([
                'success' => false,
                'message' => 'The order ID / transaction ID is required.',
                'errors' => ['order_id' => ['The order_id or txnid is required.']],
            ], 422);
        }

        $advertisement = $this->service->verifyPayment(
            $request->user(),
            (string) $orderId,
            (string) $paymentId,
            (string) $signature
        );

        $advertisement->load(['package', 'foodItem.category', 'restaurant', 'payments']);

        $message = match ($advertisement->payment_status) {
            RestaurantAdvertisement::PAYMENT_PAID => 'Payment successful. Your advertisement is now active.',
            RestaurantAdvertisement::PAYMENT_FAILED => 'Payment failed. Please try again.',
            default => 'Payment is being processed. We will update the status shortly.',
        };

        return response()->json([
            'success' => $advertisement->payment_status === RestaurantAdvertisement::PAYMENT_PAID,
            'message' => $message,
            'data' => [
                'advertisement' => $this->formatAdvertisement($advertisement, true),
            ],
        ], $advertisement->payment_status === RestaurantAdvertisement::PAYMENT_FAILED ? 402 : 200);
    }

    /**
     * Gateway server-to-server webhook (no auth; signature checked).
     */
    public function webhook(Request $request)
    {
        try {
            $signature = $request->header('X-PayU-Signature')
                ?? $request->input('hash');

            $result = $this->service->handleWebhook(
                $request->getContent(),
                $signature
            );
        } catch (AdvertisementException $e) {
            Log::warning('Advertisement webhook rejected', ['message' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->status());
        }

        return response()->json(['success' => true, 'result' => $result]);
    }

    /**
     * Browser callback after PayU checkout (POST / GET from PayU hosted checkout).
     */
    public function callback(Request $request)
    {
        Log::info('Advertisement PayU browser callback reached', $request->all());

        $txnid = (string) $request->input('txnid', '');
        $status = strtolower((string) $request->input('status', ''));
        $mihpayid = (string) ($request->input('mihpayid') ?? $request->input('payment_id') ?? $txnid);
        $hash = (string) $request->input('hash', '');

        if (empty($txnid)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payment callback: transaction ID missing.',
            ], 422);
        }

        try {
            $payment = RestaurantAdPayment::where('gateway_order_id', $txnid)->first();
            if (!$payment) {
                return response()->json(['success' => false, 'message' => 'Payment record not found.'], 404);
            }

            $ad = $this->service->verifyPayment(
                $payment->advertisement->restaurant,
                $txnid,
                $mihpayid,
                $hash
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => $ad->payment_status === RestaurantAdvertisement::PAYMENT_PAID,
                    'message' => 'Payment verified. Advertisement is now active.',
                    'data' => ['advertisement' => $this->formatAdvertisement($ad, true)],
                ]);
            }

            return redirect()->to(rtrim((string) config('app.url'), '/') . '/payment/result?status=' . ($ad->payment_status === RestaurantAdvertisement::PAYMENT_PAID ? 'paid' : 'failed') . '&order_id=' . $ad->id, 303);
        } catch (\Throwable $e) {
            Log::error('Advertisement callback error', ['error' => $e->getMessage()]);

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()->to(rtrim((string) config('app.url'), '/') . '/payment/result?status=failed&order_id=' . $txnid, 303);
        }
    }

    /**
     * Unpaid: cancels now. Paid: sends a cancellation request to admin.
     */
    public function cancel(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $advertisement = $this->service->cancelByRestaurant($request->user(), (int) $id, $request->reason);
        $advertisement->load(['package', 'foodItem.category', 'restaurant']);

        return response()->json([
            'success' => true,
            'message' => $advertisement->status === RestaurantAdvertisement::STATUS_CANCELLED
                ? 'Advertisement cancelled.'
                : 'Cancellation request sent to admin.',
            'data' => [
                'advertisement' => $this->formatAdvertisement($advertisement),
            ],
        ]);
    }

    private function formatPackage(RestaurantAdPackage $package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'code' => $package->code,
            'description' => $package->description,
            'placement' => $package->placement,
            'requires_image' => (bool) $package->requires_image,
            'requires_food_item' => $package->placement === RestaurantAdvertisement::TARGET_FOOD_ITEM,
            'durations' => $package->activeDurations->map(function ($duration) {
                $price = $this->service->calculatePrice($duration);

                return [
                    'id' => $duration->id,
                    'duration_days' => $duration->duration_days,
                    'price' => $price['amount'],
                    'tax_amount' => $price['tax_amount'],
                    'total_amount' => $price['total_amount'],
                ];
            })->values(),
        ];
    }

    private function formatAdvertisement(RestaurantAdvertisement $advertisement, bool $withPayments = false): array
    {
        $target = match ($advertisement->target_type) {
            RestaurantAdvertisement::TARGET_FOOD_ITEM => [
                'type' => 'food_item',
                'id' => $advertisement->target_id,
                'name' => $advertisement->foodItem?->name,
                'category' => $advertisement->foodItem?->category?->name,
                'image' => $advertisement->foodItem?->image,
            ],
            RestaurantAdvertisement::TARGET_RESTAURANT => [
                'type' => 'restaurant',
                'id' => $advertisement->target_id,
                'name' => $advertisement->restaurant?->restaurant_name,
            ],
            RestaurantAdvertisement::TARGET_HOME => ['type' => 'home', 'id' => null, 'name' => 'Customer Home Screen'],
            RestaurantAdvertisement::TARGET_CHECKOUT => ['type' => 'checkout', 'id' => null, 'name' => 'Checkout Page'],
            default => ['type' => $advertisement->target_type, 'id' => $advertisement->target_id, 'name' => null],
        };

        $data = [
            'id' => $advertisement->id,
            'package' => [
                'id' => $advertisement->package?->id,
                'name' => $advertisement->package?->name,
                'code' => $advertisement->package?->code,
            ],
            'target' => $target,
            'image' => $advertisement->image,
            'image_url' => $advertisement->imageUrl(),
            'duration_days' => $advertisement->duration_days,
            'amount' => $advertisement->amount,
            'tax_percentage' => $advertisement->tax_percentage,
            'tax_amount' => $advertisement->tax_amount,
            'total_amount' => $advertisement->total_amount,
            'payment_status' => $advertisement->payment_status,
            'payment_method' => $advertisement->payment_method,
            'payment_reference' => $advertisement->payment_reference,
            'status' => $advertisement->displayStatus(),
            'can_pay' => in_array($advertisement->status, [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING], true)
                && $advertisement->payment_status !== RestaurantAdvertisement::PAYMENT_PAID,
            'start_at' => $advertisement->start_at,
            'end_at' => $advertisement->end_at,
            'paid_at' => $advertisement->paid_at,
            'cancellation_requested_at' => $advertisement->cancellation_requested_at,
            'cancellation_reason' => $advertisement->cancellation_reason,
            'cancelled_at' => $advertisement->cancelled_at,
            'refunded_at' => $advertisement->refunded_at,
            'payment_link' => route('advertisement.pay', ['id' => $advertisement->id]),
            'created_at' => $advertisement->created_at,
        ];

        if ($withPayments) {
            $data['payments'] = $advertisement->payments
                ->sortByDesc('id')
                ->map(fn ($payment) => [
                    'id' => $payment->id,
                    'gateway' => $payment->gateway,
                    'order_id' => $payment->gateway_order_id,
                    'payment_id' => $payment->gateway_payment_id,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                    'failure_reason' => $payment->failure_reason,
                    'paid_at' => $payment->paid_at,
                    'created_at' => $payment->created_at,
                ])
                ->values();
        }

        return $data;
    }
}
