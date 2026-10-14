<?php

namespace App\Services\Restaurant;

use App\Exceptions\AdvertisementException;
use App\Models\Admin;
use App\Models\Restaurant;
use App\Models\RestaurantAdPackage;
use App\Models\RestaurantAdPackageDuration;
use App\Models\RestaurantAdPayment;
use App\Models\RestaurantAdvertisement;
use App\Models\RestaurantAdvertisementHistory;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantNotification;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayException;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Single place for advertisement business rules. Restaurant, Admin and
 * Super Admin controllers all go through here; customer controllers use
 * the "Customer display" methods.
 */
class RestaurantAdvertisementService
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Packages
    |--------------------------------------------------------------------------
    */

    /**
     * Active packages with their active durations, in display order.
     */
    public function activePackages(): Collection
    {
        return RestaurantAdPackage::query()
            ->where('is_active', true)
            ->with('activeDurations')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    public function findActivePackage(int $packageId): RestaurantAdPackage
    {
        $package = RestaurantAdPackage::query()
            ->with('activeDurations')
            ->find($packageId);

        if (!$package || !$package->is_active) {
            throw AdvertisementException::notFound('Advertisement package not found.');
        }

        return $package;
    }

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    /**
     * Price comes only from the duration row; GST rate from config.
     *
     * @return array{amount: float, tax_percentage: float, tax_amount: float, total_amount: float}
     */
    public function calculatePrice(RestaurantAdPackageDuration $duration): array
    {
        $amount = round((float) $duration->price, 2);
        $taxPercentage = (float) config('advertisement.gst_percentage', 18);
        $taxAmount = round($amount * $taxPercentage / 100, 2);

        return [
            'amount' => $amount,
            'tax_percentage' => $taxPercentage,
            'tax_amount' => $taxAmount,
            'total_amount' => round($amount + $taxAmount, 2),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Create (draft)
    |--------------------------------------------------------------------------
    */

    /**
     * Create an unpaid advertisement for $restaurant.
     *
     * $restaurant always comes from the authenticated user (or, for admin,
     * from the admin's chosen restaurant) - never from request input.
     * Restaurants start a draft; admins create it straight as pending
     * (waiting for payment).
     *
     * @param array{package_id: mixed, package_duration_id: mixed, food_item_id?: mixed} $input
     */
    public function create(
        Restaurant $restaurant,
        array $input,
        ?UploadedFile $image,
        string $createdByType,
        ?int $createdById,
        string $initialStatus = RestaurantAdvertisement::STATUS_DRAFT,
        ?string $createdByName = null
    ): RestaurantAdvertisement {
        if (!in_array($initialStatus, [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING], true)) {
            throw AdvertisementException::invalid('status', 'A new advertisement can only be a draft or pending.');
        }

        $this->ensureRestaurantCanAdvertise($restaurant);

        $package = $this->resolvePackage($input['package_id'] ?? null);
        $duration = $this->resolveDuration($package, $input['package_duration_id'] ?? null);
        [$targetType, $targetId] = $this->resolveTarget($package, $restaurant, $input['food_item_id'] ?? null);

        $this->validateImage($package, $image);

        $price = $this->calculatePrice($duration);

        // Store only after every check has passed
        $imagePath = $image ? $image->store(config('advertisement.image.directory', 'restaurant_ads'), 'public') : null;

        try {
            $advertisement = DB::transaction(function () use ($restaurant, $package, $duration, $targetType, $targetId, $imagePath, $price, $initialStatus, $createdByType, $createdById, $createdByName) {
                $advertisement = RestaurantAdvertisement::create([
                    'restaurant_id' => $restaurant->id,
                    'package_id' => $package->id,
                    'package_duration_id' => $duration->id,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'image' => $imagePath,
                    'duration_days' => $duration->duration_days,
                    'amount' => $price['amount'],
                    'tax_percentage' => $price['tax_percentage'],
                    'tax_amount' => $price['tax_amount'],
                    'total_amount' => $price['total_amount'],
                    'payment_status' => RestaurantAdvertisement::PAYMENT_PENDING,
                    'status' => $initialStatus,
                    'created_by_type' => $createdByType,
                    'created_by_id' => $createdById,
                ]);

                $this->record($advertisement, 'created', [
                    'type' => $createdByType,
                    'id' => $createdById,
                    'name' => $createdByName ?? ($createdByType === RestaurantAdvertisement::CREATED_BY_RESTAURANT ? $restaurant->restaurant_name : null),
                ], null, $package->name . ' - ' . $duration->duration_days . ' day(s), total ' . number_format($price['total_amount'], 2));

                return $advertisement;
            });

            return $advertisement->load(['package', 'foodItem.category', 'restaurant']);
        } catch (\Throwable $e) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $e;
        }
    }

    private function ensureRestaurantCanAdvertise(Restaurant $restaurant): void
    {
        if ($restaurant->status !== 'approved') {
            throw AdvertisementException::forbidden('Only approved restaurants can advertise.');
        }
    }

    private function resolvePackage(mixed $packageId): RestaurantAdPackage
    {
        if (!filter_var($packageId, FILTER_VALIDATE_INT)) {
            throw AdvertisementException::invalid('package_id', 'Please select a valid package.');
        }

        $package = RestaurantAdPackage::find((int) $packageId);

        if (!$package) {
            throw AdvertisementException::invalid('package_id', 'Selected package does not exist.');
        }

        if (!$package->is_active) {
            throw AdvertisementException::invalid('package_id', 'Selected package is not available right now.');
        }

        return $package;
    }

    private function resolveDuration(RestaurantAdPackage $package, mixed $durationId): RestaurantAdPackageDuration
    {
        if (!filter_var($durationId, FILTER_VALIDATE_INT)) {
            throw AdvertisementException::invalid('package_duration_id', 'Please select a valid duration.');
        }

        $duration = RestaurantAdPackageDuration::find((int) $durationId);

        if (!$duration) {
            throw AdvertisementException::invalid('package_duration_id', 'Selected duration does not exist.');
        }

        if ((int) $duration->package_id !== (int) $package->id) {
            throw AdvertisementException::invalid('package_duration_id', 'Selected duration does not belong to this package.');
        }

        if (!$duration->is_active) {
            throw AdvertisementException::invalid('package_duration_id', 'Selected duration is not available right now.');
        }

        return $duration;
    }

    /**
     * target_type / target_id are decided here from the package, never
     * taken from the request.
     *
     * @return array{0: string, 1: ?int}
     */
    private function resolveTarget(RestaurantAdPackage $package, Restaurant $restaurant, mixed $foodItemId): array
    {
        $isFoodPackage = $package->placement === RestaurantAdvertisement::TARGET_FOOD_ITEM;

        if (!$isFoodPackage && $foodItemId !== null && $foodItemId !== '') {
            throw AdvertisementException::invalid('food_item_id', 'A food item can only be selected for Food Item Promotion.');
        }

        return match ($package->placement) {
            RestaurantAdvertisement::TARGET_HOME => [RestaurantAdvertisement::TARGET_HOME, null],
            RestaurantAdvertisement::TARGET_CHECKOUT => [RestaurantAdvertisement::TARGET_CHECKOUT, null],
            RestaurantAdvertisement::TARGET_RESTAURANT => [RestaurantAdvertisement::TARGET_RESTAURANT, $restaurant->id],
            RestaurantAdvertisement::TARGET_FOOD_ITEM => [
                RestaurantAdvertisement::TARGET_FOOD_ITEM,
                $this->resolveFoodItem($restaurant, $foodItemId)->id,
            ],
            default => throw AdvertisementException::invalid('package_id', 'This package is not configured correctly.'),
        };
    }

    /**
     * Food item must exist, belong to $restaurant and be active (with an
     * active category). One food item per advertisement.
     */
    public function resolveFoodItem(Restaurant $restaurant, mixed $foodItemId): RestaurantMenuItem
    {
        if (is_array($foodItemId)) {
            throw AdvertisementException::invalid('food_item_id', 'Only one food item can be promoted per advertisement.');
        }

        if (!filter_var($foodItemId, FILTER_VALIDATE_INT)) {
            throw AdvertisementException::invalid('food_item_id', 'Please select a food item to promote.');
        }

        $item = RestaurantMenuItem::query()->with('category')->find((int) $foodItemId);

        if (!$item) {
            throw AdvertisementException::invalid('food_item_id', 'Selected food item does not exist.');
        }

        if ((int) $item->restaurant_id !== (int) $restaurant->id) {
            throw AdvertisementException::forbidden('Selected food item does not belong to this restaurant.');
        }

        if (!$item->is_active || !$item->category || !$item->category->is_active) {
            throw AdvertisementException::invalid('food_item_id', 'Selected food item is not active.');
        }

        return $item;
    }

    private function validateImage(RestaurantAdPackage $package, ?UploadedFile $image): void
    {
        if (!$package->requires_image) {
            if ($image) {
                throw AdvertisementException::invalid('image', 'This package does not use an image.');
            }

            return;
        }

        $rules = config('advertisement.image');

        $validator = Validator::make(
            ['image' => $image],
            [
                'image' => [
                    'required',
                    'file',
                    'image',
                    'mimes:' . implode(',', $rules['mimes']),
                    'extensions:' . implode(',', $rules['mimes']),
                    'max:' . $rules['max_kb'],
                    Rule::dimensions()->minWidth($rules['min_width'])->minHeight($rules['min_height']),
                ],
            ],
            [
                'image.required' => 'Advertisement image is required for this package.',
                'image.dimensions' => "Image must be at least {$rules['min_width']}x{$rules['min_height']} pixels.",
            ]
        );

        if ($validator->fails()) {
            throw AdvertisementException::invalidMany($validator->errors()->toArray(), $validator->errors()->first());
        }

        $ratio = $rules['aspect_ratio'][$package->placement] ?? null;

        if ($ratio) {
            [$width, $height] = getimagesize($image->getRealPath());
            $tolerance = (float) ($rules['aspect_ratio_tolerance'] ?? 0.05);

            if (!$height || abs(($width / $height) - $ratio) / $ratio > $tolerance) {
                throw AdvertisementException::invalid('image', 'Image does not have the required aspect ratio.');
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Edit (admin, before payment)
    |--------------------------------------------------------------------------
    */

    /**
     * Change package, duration, food item or image of an unpaid draft /
     * pending ad. The restaurant never changes. Everything is re-validated
     * and re-priced from the database, and any open gateway order is
     * closed so the old amount can no longer activate the ad.
     *
     * @param array{package_id?: mixed, package_duration_id?: mixed, food_item_id?: mixed} $input
     */
    public function updateUnpaid(RestaurantAdvertisement $advertisement, array $input, ?UploadedFile $image, array $actor): RestaurantAdvertisement
    {
        $this->ensureEditable($advertisement);

        $restaurant = $advertisement->restaurant;
        $this->ensureRestaurantCanAdvertise($restaurant);

        $package = $this->resolvePackage($input['package_id'] ?? $advertisement->package_id);
        $samePackage = (int) $package->id === (int) $advertisement->package_id;

        $duration = $this->resolveDuration(
            $package,
            $input['package_duration_id'] ?? ($samePackage ? $advertisement->package_duration_id : null)
        );

        $foodItemId = array_key_exists('food_item_id', $input)
            ? $input['food_item_id']
            : ($samePackage && $package->placement === RestaurantAdvertisement::TARGET_FOOD_ITEM ? $advertisement->target_id : null);

        [$targetType, $targetId] = $this->resolveTarget($package, $restaurant, $foodItemId);

        // A new upload is checked in full; otherwise an image package keeps the current one
        if ($image || !$package->requires_image) {
            $this->validateImage($package, $image);
        } elseif (!$advertisement->image) {
            throw AdvertisementException::invalid('image', 'Advertisement image is required for this package.');
        }

        $price = $this->calculatePrice($duration);
        $newImagePath = $image ? $image->store(config('advertisement.image.directory', 'restaurant_ads'), 'public') : null;
        $oldImagePath = $advertisement->image;

        try {
            $advertisement = DB::transaction(function () use ($advertisement, $package, $duration, $targetType, $targetId, $price, $newImagePath, $actor) {
                $advertisement = RestaurantAdvertisement::query()->lockForUpdate()->findOrFail($advertisement->id);

                $this->ensureEditable($advertisement);

                $before = $advertisement->only(['package_id', 'package_duration_id', 'target_id', 'total_amount', 'image']);

                // Orders opened for the old configuration must not activate this ad anymore
                $advertisement->payments()
                    ->where('status', RestaurantAdPayment::STATUS_CREATED)
                    ->update([
                        'status' => RestaurantAdPayment::STATUS_FAILED,
                        'failure_reason' => 'Closed: advertisement was edited before payment.',
                        'updated_at' => now(),
                    ]);

                $advertisement->update([
                    'package_id' => $package->id,
                    'package_duration_id' => $duration->id,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'image' => $package->requires_image ? ($newImagePath ?? $advertisement->image) : null,
                    'duration_days' => $duration->duration_days,
                    'amount' => $price['amount'],
                    'tax_percentage' => $price['tax_percentage'],
                    'tax_amount' => $price['tax_amount'],
                    'total_amount' => $price['total_amount'],
                    'payment_status' => RestaurantAdvertisement::PAYMENT_PENDING,
                ]);

                $after = $advertisement->only(['package_id', 'package_duration_id', 'target_id', 'total_amount', 'image']);

                $this->record($advertisement, 'updated', $actor, $advertisement->status, $package->name . ' - ' . $duration->duration_days . ' day(s), total ' . number_format($price['total_amount'], 2), [
                    'before' => $before,
                    'after' => $after,
                ]);

                return $advertisement;
            });
        } catch (\Throwable $e) {
            if ($newImagePath) {
                Storage::disk('public')->delete($newImagePath);
            }

            throw $e;
        }

        if ($oldImagePath && $oldImagePath !== $advertisement->image) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return $advertisement->refresh()->load(['package', 'packageDuration', 'foodItem.category', 'restaurant', 'payments']);
    }

    private function ensureEditable(RestaurantAdvertisement $advertisement): void
    {
        $unpaid = in_array($advertisement->status, [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING], true)
            && in_array($advertisement->payment_status, [RestaurantAdvertisement::PAYMENT_PENDING, RestaurantAdvertisement::PAYMENT_FAILED], true);

        if (!$unpaid) {
            throw AdvertisementException::invalid('status', 'Only unpaid draft or pending advertisements can be edited.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Admin access
    |--------------------------------------------------------------------------
    */

    public function findForAdmin(int $advertisementId): RestaurantAdvertisement
    {
        $advertisement = RestaurantAdvertisement::query()
            ->with(['package', 'packageDuration', 'foodItem.category', 'restaurant.address', 'payments'])
            ->find($advertisementId);

        if (!$advertisement) {
            throw AdvertisementException::notFound();
        }

        return $advertisement;
    }

    /**
     * Who is acting, as stored on created_by / history rows. Admins and
     * super admins share the admins table; only the type differs.
     *
     * @return array{type: string, id: int, name: ?string}
     */
    public function adminActor(Admin $admin): array
    {
        return [
            'type' => $admin->isSuperAdmin()
                ? RestaurantAdvertisement::CREATED_BY_SUPER_ADMIN
                : RestaurantAdvertisement::CREATED_BY_ADMIN,
            'id' => $admin->id,
            'name' => $admin->name,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Restaurant access
    |--------------------------------------------------------------------------
    */

    /**
     * Load an advertisement and make sure it belongs to $restaurant.
     */
    public function findForRestaurant(Restaurant $restaurant, int $advertisementId): RestaurantAdvertisement
    {
        $advertisement = RestaurantAdvertisement::query()
            ->with(['package', 'packageDuration', 'foodItem.category', 'restaurant', 'payments'])
            ->find($advertisementId);

        if (!$advertisement) {
            throw AdvertisementException::notFound();
        }

        if ((int) $advertisement->restaurant_id !== (int) $restaurant->id) {
            throw AdvertisementException::forbidden();
        }

        return $advertisement;
    }

    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    */

    /**
     * Start (or resume) payment for an unpaid advertisement. Re-checks the
     * package, duration and target, and re-prices from the database.
     *
     * @return array{advertisement: RestaurantAdvertisement, payment: RestaurantAdPayment, checkout: array}
     */
    public function initiatePayment(Restaurant $restaurant, int $advertisementId): array
    {
        $advertisement = $this->findForRestaurant($restaurant, $advertisementId);

        return $this->startPayment($advertisement, [
            'type' => RestaurantAdvertisementHistory::ACTOR_RESTAURANT,
            'id' => $restaurant->id,
            'name' => $restaurant->restaurant_name,
        ]);
    }

    /**
     * Shared by the restaurant app and the admin panel ("Collect online
     * payment"). The ad's own restaurant is always used.
     *
     * @return array{advertisement: RestaurantAdvertisement, payment: ?RestaurantAdPayment, checkout: ?array}
     */
    public function startPayment(RestaurantAdvertisement $advertisement, array $actor): array
    {
        $restaurant = $advertisement->restaurant;

        $this->ensureRestaurantCanAdvertise($restaurant);

        if ($advertisement->payment_status === RestaurantAdvertisement::PAYMENT_PAID) {
            throw AdvertisementException::conflict('This advertisement is already paid.');
        }

        if (!in_array($advertisement->status, [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING], true)) {
            throw AdvertisementException::invalid('advertisement', 'This advertisement can no longer be paid.');
        }

        // Package or duration may have been switched off since the draft was made
        $package = $this->resolvePackage($advertisement->package_id);
        $duration = $this->resolveDuration($package, $advertisement->package_duration_id);
        [$targetType, $targetId] = $this->resolveTarget(
            $package,
            $restaurant,
            $package->placement === RestaurantAdvertisement::TARGET_FOOD_ITEM ? $advertisement->target_id : null
        );

        $price = $this->calculatePrice($duration);

        $advertisement->fill([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'duration_days' => $duration->duration_days,
            'amount' => $price['amount'],
            'tax_percentage' => $price['tax_percentage'],
            'tax_amount' => $price['tax_amount'],
            'total_amount' => $price['total_amount'],
        ]);

        // Online payment switched off: go live now, payment stays pending (pay later)
        if (!config('advertisement.payment_enabled', true)) {
            DB::transaction(fn () => $this->activate(
                $advertisement,
                null,
                'pay_later',
                now(),
                null,
                RestaurantAdvertisement::PAYMENT_PENDING,
                $actor,
                'Activated as pay later (online payment is switched off).'
            ));

            $advertisement->refresh();
            $this->notifyActivated($advertisement);

            return [
                'advertisement' => $advertisement->load(['package', 'foodItem.category', 'restaurant', 'payments']),
                'payment' => null,
                'checkout' => null,
            ];
        }

        // Re-use a recent unpaid order for the same amount instead of opening a new one
        $payment = $advertisement->payments()
            ->where('status', RestaurantAdPayment::STATUS_CREATED)
            ->where('gateway', $this->gateway->name())
            ->where('amount', $price['total_amount'])
            ->where('created_at', '>', now()->subMinutes(30))
            ->latest('id')
            ->first();

        if (!$payment) {
            $currency = config('advertisement.currency', 'INR');

            try {
                $order = $this->gateway->createOrder(
                    $this->toPaise($price['total_amount']),
                    $currency,
                    'AD-' . $advertisement->id . '-' . now()->format('YmdHis'),
                    [
                        'advertisement_id' => (string) $advertisement->id,
                        'restaurant_id' => (string) $restaurant->id,
                        'package' => $package->code,
                        'restaurant_name' => $restaurant->owner_name ?: $restaurant->name,
                        'email' => $restaurant->email,
                        'mobile' => $restaurant->mobile,
                    ]
                );
            } catch (PaymentGatewayException $e) {
                Log::error('Advertisement payment order failed', [
                    'advertisement_id' => $advertisement->id,
                    'error' => $e->getMessage(),
                ]);

                throw new AdvertisementException('Unable to start payment right now. Please try again.', 502);
            }

            $payment = $advertisement->payments()->create([
                'gateway' => $this->gateway->name(),
                'gateway_order_id' => $order['id'],
                'amount' => $price['total_amount'],
                'currency' => $currency,
                'status' => RestaurantAdPayment::STATUS_CREATED,
                'gateway_response' => $order['raw'],
            ]);
        }

        $fromStatus = $advertisement->getOriginal('status');

        $advertisement->fill([
            'status' => RestaurantAdvertisement::STATUS_PENDING,
            'payment_status' => RestaurantAdvertisement::PAYMENT_PENDING,
            'payment_method' => $this->gateway->name(),
        ])->save();

        if ($payment->wasRecentlyCreated) {
            $this->record($advertisement, 'payment_started', $actor, $fromStatus, 'Gateway order ' . $payment->gateway_order_id . ' for ' . number_format((float) $payment->amount, 2));
        }

        return [
            'advertisement' => $advertisement->refresh()->load(['package', 'foodItem.category', 'restaurant', 'payments']),
            'payment' => $payment,
            'checkout' => array_merge($this->gateway->checkoutConfig(), [
                'order_id' => $payment->gateway_order_id,
                'amount' => $this->toPaise($payment->amount),
                'currency' => $payment->currency,
                'name' => 'VegGo',
                'description' => $package->name . ' - ' . $advertisement->duration_days . ' day(s)',
                'payment_url' => config('services.payu.base_url', 'https://test.payu.in/_payment'),
                'params' => $payment->gateway_response,
                'prefill' => [
                    'name' => $restaurant->owner_name ?: $restaurant->name,
                    'email' => $restaurant->email,
                    'contact' => $restaurant->mobile,
                ],
            ]),
        ];
    }

    /**
     * Called by the app after the gateway checkout returns. The app's
     * "success" is never trusted: the signature is checked and the payment
     * is fetched from the gateway before anything is activated.
     */
    public function verifyPayment(Restaurant $restaurant, string $orderId, string $paymentId, string $signature): RestaurantAdvertisement
    {
        $payment = RestaurantAdPayment::query()
            ->with('advertisement')
            ->where('gateway_order_id', $orderId)
            ->first();

        if (!$payment || !$payment->advertisement) {
            throw AdvertisementException::notFound('Payment not found.');
        }

        if ((int) $payment->advertisement->restaurant_id !== (int) $restaurant->id) {
            throw AdvertisementException::forbidden();
        }

        return $this->verifyCheckout($payment, $orderId, $paymentId, $signature);
    }

    /**
     * Admin panel checkout result for one advertisement. The order must
     * belong to that advertisement.
     */
    public function verifyPaymentForAdvertisement(RestaurantAdvertisement $advertisement, string $orderId, string $paymentId, string $signature): RestaurantAdvertisement
    {
        $payment = RestaurantAdPayment::query()
            ->with('advertisement')
            ->where('gateway_order_id', $orderId)
            ->where('advertisement_id', $advertisement->id)
            ->first();

        if (!$payment) {
            throw AdvertisementException::notFound('Payment not found for this advertisement.');
        }

        return $this->verifyCheckout($payment, $orderId, $paymentId, $signature);
    }

    private function verifyCheckout(RestaurantAdPayment $payment, string $orderId, string $paymentId, string $signature): RestaurantAdvertisement
    {
        if ($payment->gateway !== $this->gateway->name()) {
            throw AdvertisementException::invalid('order_id', 'This payment was made with a different gateway.');
        }

        // Same payment already applied (double tap / callback + webhook)
        if ($payment->status === RestaurantAdPayment::STATUS_PAID && $payment->gateway_payment_id === $paymentId) {
            return $payment->advertisement->refresh();
        }

        if (!$this->gateway->verifyCheckoutSignature($orderId, $paymentId, $signature)) {
            Log::warning('Advertisement payment signature mismatch', [
                'advertisement_id' => $payment->advertisement_id,
                'order_id' => $orderId,
            ]);

            throw AdvertisementException::invalid('signature', 'Payment verification failed.');
        }

        try {
            $gatewayPayment = $this->gateway->fetchPayment($paymentId, $orderId);
        } catch (PaymentGatewayException $e) {
            throw new AdvertisementException('Unable to confirm payment right now. It will be updated automatically once confirmed.', 502);
        }

        return $this->applyGatewayPayment($payment, $gatewayPayment, 'callback');
    }

    /**
     * Gateway webhook. Signature is checked against the raw body.
     *
     * @return string what happened, for logging / response
     */
    public function handleWebhook(string $payload, ?string $signature): string
    {
        if (!$this->gateway->verifyWebhookSignature($payload, $signature)) {
            throw new AdvertisementException('Invalid webhook signature.', 401);
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            parse_str($payload, $data);
        }

        // PayU webhook / callback format (txnid)
        if (!empty($data['txnid'])) {
            $payment = RestaurantAdPayment::query()
                ->with('advertisement')
                ->where('gateway_order_id', $data['txnid'])
                ->first();

            if (!$payment || !$payment->advertisement) {
                return 'ignored';
            }

            $statusStr = strtolower((string) ($data['status'] ?? ''));
            $status = $statusStr === 'success'
                ? PaymentGateway::STATUS_PAID
                : PaymentGateway::STATUS_FAILED;

            $amountInPaise = (int) round(((float) ($data['amount'] ?? 0)) * 100);

            $this->applyGatewayPayment($payment, [
                'id' => (string) ($data['mihpayid'] ?? $data['txnid']),
                'order_id' => (string) $data['txnid'],
                'status' => $status,
                'amount' => $amountInPaise,
                'currency' => strtoupper((string) ($payment->currency ?: 'INR')),
                'method' => $data['mode'] ?? 'online',
                'error' => $data['error_Message'] ?? $data['error'] ?? null,
                'raw' => $data,
            ], 'webhook');

            return $status;
        }

        $event = $data['event'] ?? null;
        $entity = $data['payload']['payment']['entity'] ?? null;

        if (!$entity || empty($entity['order_id']) || empty($entity['id'])) {
            return 'ignored';
        }

        $payment = RestaurantAdPayment::query()
            ->with('advertisement')
            ->where('gateway_order_id', $entity['order_id'])
            ->first();

        // Not an advertisement payment
        if (!$payment || !$payment->advertisement) {
            return 'ignored';
        }

        $status = match ($event) {
            'payment.captured', 'order.paid' => PaymentGateway::STATUS_PAID,
            'payment.failed' => PaymentGateway::STATUS_FAILED,
            default => null,
        };

        if ($status === null) {
            return 'ignored';
        }

        $this->applyGatewayPayment($payment, [
            'id' => $entity['id'],
            'order_id' => $entity['order_id'],
            'status' => $status,
            'amount' => (int) ($entity['amount'] ?? 0),
            'currency' => $entity['currency'] ?? '',
            'method' => $entity['method'] ?? null,
            'error' => $entity['error_description'] ?? null,
            'raw' => $entity,
        ], 'webhook');

        return $status;
    }

    /**
     * Apply a gateway-confirmed payment state to our records.
     */
    private function applyGatewayPayment(RestaurantAdPayment $payment, array $gatewayPayment, string $via): RestaurantAdvertisement
    {
        if (($gatewayPayment['order_id'] ?? null) && $gatewayPayment['order_id'] !== $payment->gateway_order_id) {
            throw AdvertisementException::invalid('payment_id', 'Payment does not belong to this order.');
        }

        if ($gatewayPayment['status'] === PaymentGateway::STATUS_PAID) {
            $expectedCurrency = strtoupper($payment->currency);

            if ($gatewayPayment['amount'] !== $this->toPaise($payment->amount) || strtoupper($gatewayPayment['currency']) !== $expectedCurrency) {
                Log::critical('Advertisement payment amount mismatch', [
                    'advertisement_id' => $payment->advertisement_id,
                    'expected' => $this->toPaise($payment->amount),
                    'received' => $gatewayPayment['amount'],
                ]);

                $this->markFailed($payment, $gatewayPayment['id'], 'Paid amount does not match the advertisement total.', $via, $gatewayPayment['raw']);

                throw AdvertisementException::invalid('payment_id', 'Paid amount does not match. Please contact support.');
            }

            return $this->markPaid($payment, $gatewayPayment['id'], $via, $gatewayPayment['method'], $gatewayPayment['raw']);
        }

        if ($gatewayPayment['status'] === PaymentGateway::STATUS_FAILED) {
            return $this->markFailed($payment, $gatewayPayment['id'], $gatewayPayment['error'] ?? 'Payment failed.', $via, $gatewayPayment['raw']);
        }

        // Still processing at the gateway: leave as pending, webhook will finish it
        return $payment->advertisement->refresh();
    }

    /**
     * Mark a payment paid and activate its advertisement exactly once.
     */
    private function markPaid(RestaurantAdPayment $payment, string $gatewayPaymentId, string $via, ?string $method, array $raw): RestaurantAdvertisement
    {
        $activated = false;

        $advertisement = DB::transaction(function () use ($payment, $gatewayPaymentId, $via, $raw, &$activated) {
            $payment = RestaurantAdPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $advertisement = RestaurantAdvertisement::query()->lockForUpdate()->findOrFail($payment->advertisement_id);

            // Already processed: never activate twice
            if ($payment->status === RestaurantAdPayment::STATUS_PAID) {
                return $advertisement;
            }

            $usedElsewhere = RestaurantAdPayment::query()
                ->where('gateway_payment_id', $gatewayPaymentId)
                ->where('id', '!=', $payment->id)
                ->exists();

            if ($usedElsewhere) {
                throw AdvertisementException::conflict('This payment has already been used.');
            }

            $paidAt = now();

            $payment->update([
                'gateway_payment_id' => $gatewayPaymentId,
                'status' => RestaurantAdPayment::STATUS_PAID,
                'verified_via' => $via,
                'failure_reason' => null,
                'gateway_response' => $raw,
                'paid_at' => $paidAt,
            ]);

            $gatewayActor = ['type' => RestaurantAdvertisementHistory::ACTOR_GATEWAY, 'id' => null, 'name' => $payment->gateway];

            // A second, separate payment for an ad that is already paid
            if ($advertisement->payment_status === RestaurantAdvertisement::PAYMENT_PAID
                || $advertisement->payment_status === RestaurantAdvertisement::PAYMENT_REFUNDED) {
                $payment->update(['failure_reason' => 'Duplicate payment for an already paid advertisement. Refund required.']);

                Log::critical('Duplicate advertisement payment received', [
                    'advertisement_id' => $advertisement->id,
                    'payment_id' => $payment->id,
                    'gateway_payment_id' => $gatewayPaymentId,
                ]);

                $this->record($advertisement, 'payment_received', $gatewayActor, $advertisement->status, 'Duplicate payment ' . $gatewayPaymentId . ' - refund required.');

                return $advertisement;
            }

            // Order opened before an admin edit changed the price
            if ($this->toPaise($payment->amount) !== $this->toPaise($advertisement->total_amount)) {
                $payment->update(['failure_reason' => 'Paid amount does not match the current advertisement total. Refund required.']);

                Log::critical('Advertisement paid with an outdated amount', [
                    'advertisement_id' => $advertisement->id,
                    'payment_id' => $payment->id,
                    'paid' => $payment->amount,
                    'expected' => $advertisement->total_amount,
                ]);

                $this->record($advertisement, 'payment_received', $gatewayActor, $advertisement->status, 'Payment ' . $gatewayPaymentId . ' was for an outdated amount - refund required. Advertisement not activated.');

                return $advertisement;
            }

            $this->record($advertisement, 'payment_received', $gatewayActor, $advertisement->status, 'Payment ' . $gatewayPaymentId . ' verified via ' . $via . '.');

            $this->activate($advertisement, $gatewayPaymentId, $payment->gateway, $paidAt, null, RestaurantAdvertisement::PAYMENT_PAID, $gatewayActor);
            $activated = true;

            return $advertisement;
        });

        if ($activated) {
            $this->notifyActivated($advertisement);
        }

        return $advertisement->refresh();
    }

    private function markFailed(RestaurantAdPayment $payment, ?string $gatewayPaymentId, string $reason, string $via, array $raw): RestaurantAdvertisement
    {
        return DB::transaction(function () use ($payment, $gatewayPaymentId, $reason, $via, $raw) {
            $payment = RestaurantAdPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $advertisement = RestaurantAdvertisement::query()->lockForUpdate()->findOrFail($payment->advertisement_id);

            // A paid result always wins over a late failure event
            if ($payment->status === RestaurantAdPayment::STATUS_PAID) {
                return $advertisement;
            }

            $paymentIdTaken = $gatewayPaymentId && RestaurantAdPayment::query()
                ->where('gateway_payment_id', $gatewayPaymentId)
                ->where('id', '!=', $payment->id)
                ->exists();

            $payment->update([
                'gateway_payment_id' => $paymentIdTaken ? $payment->gateway_payment_id : ($gatewayPaymentId ?? $payment->gateway_payment_id),
                'status' => RestaurantAdPayment::STATUS_FAILED,
                'verified_via' => $via,
                'failure_reason' => mb_substr($reason, 0, 500),
                'gateway_response' => $raw,
            ]);

            // Ad stays pending so the restaurant can retry; it is NOT activated
            if ($advertisement->payment_status !== RestaurantAdvertisement::PAYMENT_PAID) {
                $advertisement->update(['payment_status' => RestaurantAdvertisement::PAYMENT_FAILED]);
            }

            $this->record(
                $advertisement,
                'payment_failed',
                ['type' => RestaurantAdvertisementHistory::ACTOR_GATEWAY, 'id' => null, 'name' => $payment->gateway],
                $advertisement->status,
                mb_substr($reason, 0, 500)
            );

            return $advertisement->refresh();
        });
    }

    /**
     * Turn a paid advertisement on. start_at is the payment time; if the
     * same restaurant already has a running ad for the same placement and
     * target, the new one starts when that one ends so paid days are not
     * lost to overlap.
     *
     * $paymentStatus is pending only for "pay later" (online payment off).
     */
    private function activate(
        RestaurantAdvertisement $advertisement,
        ?string $paymentReference,
        ?string $paymentMethod,
        CarbonInterface $paidAt,
        ?int $paidByAdminId,
        string $paymentStatus = RestaurantAdvertisement::PAYMENT_PAID,
        ?array $actor = null,
        ?string $note = null
    ): void {
        $isPaid = $paymentStatus === RestaurantAdvertisement::PAYMENT_PAID;
        $fromStatus = $advertisement->status;

        $runningUntil = RestaurantAdvertisement::query()
            ->where('restaurant_id', $advertisement->restaurant_id)
            ->where('target_type', $advertisement->target_type)
            ->where(fn ($q) => $advertisement->target_id === null
                ? $q->whereNull('target_id')
                : $q->where('target_id', $advertisement->target_id))
            ->whereIn('status', [RestaurantAdvertisement::STATUS_ACTIVE, RestaurantAdvertisement::STATUS_PAUSED])
            ->whereIn('payment_status', [RestaurantAdvertisement::PAYMENT_PAID, RestaurantAdvertisement::PAYMENT_PENDING])
            ->where('id', '!=', $advertisement->id)
            ->where('end_at', '>', $paidAt)
            ->max('end_at');

        $startAt = $runningUntil ? Carbon::parse($runningUntil) : $paidAt->copy();

        $advertisement->update([
            'payment_status' => $paymentStatus,
            'payment_reference' => $paymentReference,
            'payment_method' => $paymentMethod,
            'paid_at' => $isPaid ? $paidAt : null,
            'paid_by_admin_id' => $paidByAdminId,
            'status' => RestaurantAdvertisement::STATUS_ACTIVE,
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addDays($advertisement->duration_days),
            'cancelled_at' => null,
            'cancelled_by_type' => null,
            'cancelled_by_id' => null,
        ]);

        $this->record(
            $advertisement,
            'activated',
            $actor,
            $fromStatus,
            $note ?? ('Runs ' . $advertisement->start_at->format('d M Y h:i A') . ' - ' . $advertisement->end_at->format('d M Y h:i A') . '.')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation (restaurant)
    |--------------------------------------------------------------------------
    */

    /**
     * Unpaid ads are cancelled straight away. Paid ads only get a
     * cancellation request; Admin decides on cancellation and refund.
     */
    public function cancelByRestaurant(Restaurant $restaurant, int $advertisementId, ?string $reason): RestaurantAdvertisement
    {
        $advertisement = $this->findForRestaurant($restaurant, $advertisementId);
        $actor = [
            'type' => RestaurantAdvertisementHistory::ACTOR_RESTAURANT,
            'id' => $restaurant->id,
            'name' => $restaurant->restaurant_name,
        ];

        $unpaid = in_array($advertisement->status, [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING], true)
            && $advertisement->payment_status !== RestaurantAdvertisement::PAYMENT_PAID;

        if ($unpaid) {
            $fromStatus = $advertisement->status;

            $advertisement->update([
                'status' => RestaurantAdvertisement::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
                'cancelled_by_type' => RestaurantAdvertisement::CREATED_BY_RESTAURANT,
                'cancelled_by_id' => $restaurant->id,
            ]);

            $this->record($advertisement, 'cancelled', $actor, $fromStatus, $reason);

            return $advertisement->refresh();
        }

        // Live ads (paid, or pay later) only get a request; admin decides
        $running = in_array($advertisement->status, [RestaurantAdvertisement::STATUS_ACTIVE, RestaurantAdvertisement::STATUS_PAUSED], true)
            && in_array($advertisement->payment_status, [RestaurantAdvertisement::PAYMENT_PAID, RestaurantAdvertisement::PAYMENT_PENDING], true)
            && $advertisement->end_at?->gt(now());

        if (!$running) {
            throw AdvertisementException::invalid('advertisement', 'This advertisement cannot be cancelled.');
        }

        if ($advertisement->cancellation_requested_at) {
            throw AdvertisementException::conflict('Cancellation has already been requested.');
        }

        $advertisement->update([
            'cancellation_requested_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->record($advertisement, 'cancellation_requested', $actor, $advertisement->status, $reason);

        return $advertisement->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Admin / Super Admin actions
    |--------------------------------------------------------------------------
    */

    /**
     * Turn on a pending ad from the admin panel. Only a paid ad, or any
     * unpaid ad while online payment is switched off (pay later), may go
     * live; otherwise the payment has to come first (gateway or Mark Paid).
     */
    public function activateByAdmin(RestaurantAdvertisement $advertisement, array $actor): RestaurantAdvertisement
    {
        $activated = DB::transaction(function () use ($advertisement, $actor) {
            $advertisement = RestaurantAdvertisement::query()->lockForUpdate()->findOrFail($advertisement->id);

            if (!in_array($advertisement->status, [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING], true)) {
                throw AdvertisementException::invalid('status', 'Only draft or pending advertisements can be activated. Use Resume for a paused one.');
            }

            $this->ensureRestaurantCanAdvertise($advertisement->restaurant);

            if ($advertisement->payment_status === RestaurantAdvertisement::PAYMENT_PAID) {
                $paidAt = $advertisement->paid_at;

                // Paid days are counted from now, not from the old payment time
                $this->activate(
                    $advertisement,
                    $advertisement->payment_reference,
                    $advertisement->payment_method,
                    now(),
                    $advertisement->paid_by_admin_id,
                    RestaurantAdvertisement::PAYMENT_PAID,
                    $actor
                );

                if ($paidAt) {
                    $advertisement->update(['paid_at' => $paidAt]);
                }

                return $advertisement;
            }

            if (config('advertisement.payment_enabled', true)) {
                throw AdvertisementException::invalid('payment_status', 'Payment is not received yet. Collect the payment online or use Mark as Paid if the restaurant already paid.');
            }

            // Re-check package / duration / target before going live unpaid
            $package = $this->resolvePackage($advertisement->package_id);
            $this->resolveDuration($package, $advertisement->package_duration_id);
            $this->resolveTarget(
                $package,
                $advertisement->restaurant,
                $package->placement === RestaurantAdvertisement::TARGET_FOOD_ITEM ? $advertisement->target_id : null
            );

            $this->activate(
                $advertisement,
                null,
                'pay_later',
                now(),
                null,
                RestaurantAdvertisement::PAYMENT_PENDING,
                $actor,
                'Activated as pay later (online payment is switched off).'
            );

            return $advertisement;
        });

        $activated->refresh();
        $this->notifyActivated($activated);

        return $activated;
    }

    public function pause(RestaurantAdvertisement $advertisement, ?array $actor = null): RestaurantAdvertisement
    {
        if ($advertisement->status !== RestaurantAdvertisement::STATUS_ACTIVE) {
            throw AdvertisementException::invalid('status', 'Only active advertisements can be paused.');
        }

        if ($advertisement->end_at && $advertisement->end_at->lte(now())) {
            throw AdvertisementException::invalid('status', 'This advertisement has already ended.');
        }

        $advertisement->update(['status' => RestaurantAdvertisement::STATUS_PAUSED]);
        $this->record($advertisement, 'paused', $actor, RestaurantAdvertisement::STATUS_ACTIVE, 'Paused time is not added back; the ad keeps its end date.');
        $this->notify($advertisement, 'Advertisement paused', 'Admin paused your ' . $this->label($advertisement) . '.');

        return $advertisement->refresh();
    }

    /**
     * Paused time is not added back: the ad keeps its paid end_at.
     */
    public function resume(RestaurantAdvertisement $advertisement, ?array $actor = null): RestaurantAdvertisement
    {
        if ($advertisement->status !== RestaurantAdvertisement::STATUS_PAUSED) {
            throw AdvertisementException::invalid('status', 'Only paused advertisements can be resumed.');
        }

        $status = $advertisement->end_at && $advertisement->end_at->gt(now())
            ? RestaurantAdvertisement::STATUS_ACTIVE
            : RestaurantAdvertisement::STATUS_EXPIRED;

        $advertisement->update(['status' => $status]);
        $this->record(
            $advertisement,
            $status === RestaurantAdvertisement::STATUS_ACTIVE ? 'resumed' : 'expired',
            $actor,
            RestaurantAdvertisement::STATUS_PAUSED,
            $status === RestaurantAdvertisement::STATUS_ACTIVE ? null : 'Ended while paused.'
        );

        if ($status === RestaurantAdvertisement::STATUS_ACTIVE) {
            $this->notify($advertisement, 'Advertisement resumed', 'Your ' . $this->label($advertisement) . ' is live again.');
        }

        return $advertisement->refresh();
    }

    /**
     * Stops the ad for good. Payment is left as it is: a refund is a
     * separate action (markRefunded).
     */
    public function cancelByAdmin(RestaurantAdvertisement $advertisement, string $adminType, int $adminId, ?string $reason, ?string $adminName = null): RestaurantAdvertisement
    {
        if (in_array($advertisement->status, [RestaurantAdvertisement::STATUS_CANCELLED, RestaurantAdvertisement::STATUS_EXPIRED], true)) {
            throw AdvertisementException::invalid('status', 'This advertisement is already ' . $advertisement->status . '.');
        }

        $fromStatus = $advertisement->status;

        $advertisement->update([
            'status' => RestaurantAdvertisement::STATUS_CANCELLED,
            'cancellation_reason' => $reason ?? $advertisement->cancellation_reason,
            'cancelled_at' => now(),
            'cancelled_by_type' => $adminType,
            'cancelled_by_id' => $adminId,
        ]);

        $this->record($advertisement, 'cancelled', ['type' => $adminType, 'id' => $adminId, 'name' => $adminName], $fromStatus, $reason);
        $this->notify($advertisement, 'Advertisement cancelled', 'Admin cancelled your ' . $this->label($advertisement) . '.');

        return $advertisement->refresh();
    }

    /**
     * Records a refund done at the gateway / offline. Also stops the ad.
     */
    public function markRefunded(RestaurantAdvertisement $advertisement, string $adminType, int $adminId, ?string $note = null, ?string $adminName = null): RestaurantAdvertisement
    {
        if ($advertisement->payment_status !== RestaurantAdvertisement::PAYMENT_PAID) {
            throw AdvertisementException::invalid('payment_status', 'Only paid advertisements can be refunded.');
        }

        $fromStatus = $advertisement->status;

        $advertisement->update([
            'payment_status' => RestaurantAdvertisement::PAYMENT_REFUNDED,
            'refunded_at' => now(),
            'status' => $advertisement->status === RestaurantAdvertisement::STATUS_EXPIRED
                ? RestaurantAdvertisement::STATUS_EXPIRED
                : RestaurantAdvertisement::STATUS_CANCELLED,
            'cancelled_at' => $advertisement->cancelled_at ?? now(),
            'cancelled_by_type' => $advertisement->cancelled_by_type ?? $adminType,
            'cancelled_by_id' => $advertisement->cancelled_by_id ?? $adminId,
        ]);

        $this->record($advertisement, 'refunded', ['type' => $adminType, 'id' => $adminId, 'name' => $adminName], $fromStatus, $note);
        $this->notify($advertisement, 'Advertisement refunded', 'Payment for your ' . $this->label($advertisement) . ' has been refunded.');

        return $advertisement->refresh();
    }

    /**
     * Admin records a payment received outside the gateway (cash, bank
     * transfer, wallet adjustment) and activates the ad. For a "pay later"
     * ad that is already live, it only records the payment.
     *
     * The reference is optional; one is generated when it is left empty.
     */
    public function markPaidByAdmin(
        RestaurantAdvertisement $advertisement,
        int $adminId,
        ?string $reference = null,
        string $method = 'manual',
        ?array $actor = null,
        ?string $note = null
    ): RestaurantAdvertisement {
        $activated = false;
        $reference = trim((string) $reference) !== '' ? trim($reference) : null;
        $actor ??= ['type' => RestaurantAdvertisement::CREATED_BY_ADMIN, 'id' => $adminId, 'name' => null];

        DB::transaction(function () use ($advertisement, $adminId, $reference, $method, $actor, $note, &$activated) {
            $advertisement = RestaurantAdvertisement::query()->lockForUpdate()->findOrFail($advertisement->id);

            if ($advertisement->payment_status === RestaurantAdvertisement::PAYMENT_PAID) {
                throw AdvertisementException::conflict('This advertisement is already paid.');
            }

            $unpaidDraft = in_array($advertisement->status, [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING], true);
            $liveUnpaid = $advertisement->payment_status === RestaurantAdvertisement::PAYMENT_PENDING
                && in_array($advertisement->status, [RestaurantAdvertisement::STATUS_ACTIVE, RestaurantAdvertisement::STATUS_PAUSED, RestaurantAdvertisement::STATUS_EXPIRED], true);

            if (!$unpaidDraft && !$liveUnpaid) {
                throw AdvertisementException::invalid('status', 'This advertisement can no longer be paid.');
            }

            if ($unpaidDraft) {
                $this->ensureRestaurantCanAdvertise($advertisement->restaurant);
            }

            $paidAt = now();
            $reference ??= 'MANUAL-AD' . $advertisement->id . '-' . $paidAt->format('YmdHis');

            $referenceUsed = RestaurantAdvertisement::query()->where('payment_reference', $reference)->exists()
                || RestaurantAdPayment::query()->where('gateway_payment_id', $reference)->exists();

            if ($referenceUsed) {
                throw AdvertisementException::invalid('payment_reference', 'This payment reference is already used.');
            }

            // Orders still open at the gateway must not activate the ad a second time
            $advertisement->payments()
                ->where('status', RestaurantAdPayment::STATUS_CREATED)
                ->update([
                    'status' => RestaurantAdPayment::STATUS_FAILED,
                    'failure_reason' => 'Closed: payment was recorded manually by admin.',
                    'updated_at' => $paidAt,
                ]);

            $advertisement->payments()->create([
                'gateway' => $method,
                'gateway_order_id' => $method . '-' . $advertisement->id . '-' . $paidAt->format('YmdHisu'),
                'gateway_payment_id' => $reference,
                'amount' => $advertisement->total_amount,
                'currency' => config('advertisement.currency', 'INR'),
                'status' => RestaurantAdPayment::STATUS_PAID,
                'verified_via' => 'admin',
                'gateway_response' => ['marked_by_admin_id' => $adminId, 'note' => $note],
                'paid_at' => $paidAt,
            ]);

            $this->record(
                $advertisement,
                'marked_paid',
                $actor,
                $advertisement->status,
                trim('Reference ' . $reference . '. ' . (string) $note),
                ['amount' => (float) $advertisement->total_amount, 'method' => $method, 'reference' => $reference]
            );

            if ($liveUnpaid) {
                // Already running: keep its dates, just settle the payment
                $advertisement->update([
                    'payment_status' => RestaurantAdvertisement::PAYMENT_PAID,
                    'payment_reference' => $reference,
                    'payment_method' => $method,
                    'paid_at' => $paidAt,
                    'paid_by_admin_id' => $adminId,
                ]);

                return;
            }

            $this->activate($advertisement, $reference, $method, $paidAt, $adminId, RestaurantAdvertisement::PAYMENT_PAID, $actor);
            $activated = true;
        });

        $advertisement->refresh();

        if ($activated) {
            $this->notifyActivated($advertisement);
        }

        return $advertisement;
    }

    /*
    |--------------------------------------------------------------------------
    | Packages & pricing (admin)
    |--------------------------------------------------------------------------
    */

    /**
     * Every package with every duration (active or not), for the pricing screen.
     */
    public function allPackages(): Collection
    {
        return RestaurantAdPackage::query()
            ->with(['durations' => fn ($q) => $q->orderBy('duration_days')])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Name, description and on/off only. code and placement drive the
     * target rules and never change.
     *
     * @param array{name?: string, description?: ?string, is_active?: bool} $data
     */
    public function updatePackage(RestaurantAdPackage $package, array $data): RestaurantAdPackage
    {
        $package->update(array_intersect_key($data, array_flip(['name', 'description', 'is_active'])));

        return $package->refresh()->load(['durations' => fn ($q) => $q->orderBy('duration_days')]);
    }

    /**
     * Add a duration, or update the price of an existing one for the same
     * number of days. Prices of ads already created are not touched; unpaid
     * ones are re-priced when payment starts.
     */
    public function saveDuration(RestaurantAdPackage $package, int $days, float $price, bool $isActive = true): RestaurantAdPackageDuration
    {
        return RestaurantAdPackageDuration::updateOrCreate(
            ['package_id' => $package->id, 'duration_days' => $days],
            ['price' => round($price, 2), 'is_active' => $isActive]
        );
    }

    /**
     * @param array{price?: float, is_active?: bool} $data
     */
    public function updateDuration(RestaurantAdPackageDuration $duration, array $data): RestaurantAdPackageDuration
    {
        if (array_key_exists('price', $data)) {
            $data['price'] = round((float) $data['price'], 2);
        }

        $duration->update(array_intersect_key($data, array_flip(['price', 'is_active'])));

        return $duration->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Expiry
    |--------------------------------------------------------------------------
    */

    /**
     * Move active / paused ads past end_at to expired. Customer queries
     * already hide them via end_at; this keeps the stored status right.
     */
    public function expireDue(): int
    {
        $count = 0;

        RestaurantAdvertisement::query()
            ->whereIn('status', [RestaurantAdvertisement::STATUS_ACTIVE, RestaurantAdvertisement::STATUS_PAUSED])
            ->where('end_at', '<=', now())
            ->with(['package', 'foodItem'])
            ->chunkById(200, function ($advertisements) use (&$count) {
                foreach ($advertisements as $advertisement) {
                    $updated = RestaurantAdvertisement::query()
                        ->whereKey($advertisement->id)
                        ->whereIn('status', [RestaurantAdvertisement::STATUS_ACTIVE, RestaurantAdvertisement::STATUS_PAUSED])
                        ->update(['status' => RestaurantAdvertisement::STATUS_EXPIRED, 'updated_at' => now()]);

                    if ($updated) {
                        $count++;
                        $fromStatus = $advertisement->status;
                        $this->record($advertisement->refresh(), 'expired', null, $fromStatus, 'Ended on ' . $advertisement->end_at->format('d M Y h:i A') . '.');
                        $this->notify($advertisement, 'Advertisement ended', 'Your ' . $this->label($advertisement) . ' has ended.');
                    }
                }
            });

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | Customer display
    |--------------------------------------------------------------------------
    */

    /**
     * Package 1: banners for the customer Home screen.
     */
    public function homeAdvertisements(): Collection
    {
        return RestaurantAdvertisement::query()
            ->live()
            ->forTarget(RestaurantAdvertisement::TARGET_HOME)
            ->with('restaurant:id,restaurant_name,is_online')
            ->orderByDesc('start_at')
            ->get();
    }

    /**
     * Package 2: all currently promoted food items (for a Home section).
     */
    public function promotedFoodItems(): Collection
    {
        return $this->liveFoodItemQuery()
            ->with(['restaurant:id,restaurant_name,is_online', 'foodItem.category'])
            ->orderByDesc('start_at')
            ->get()
            ->unique('target_id')
            ->values();
    }

    /**
     * Package 2: the ad for one exact food item, or null.
     */
    public function foodItemAdvertisement(int $menuItemId): ?RestaurantAdvertisement
    {
        return $this->liveFoodItemQuery()
            ->where('target_id', $menuItemId)
            ->orderByDesc('start_at')
            ->first();
    }

    /**
     * Package 3: only the checkout restaurant's own ad.
     */
    public function checkoutAdvertisement(int $restaurantId): ?RestaurantAdvertisement
    {
        return RestaurantAdvertisement::query()
            ->live()
            ->forTarget(RestaurantAdvertisement::TARGET_CHECKOUT)
            ->where('restaurant_id', $restaurantId)
            ->orderByDesc('start_at')
            ->first();
    }

    /**
     * Package 4: ids of restaurants featured right now, earliest first.
     */
    public function featuredRestaurantIds(): Collection
    {
        return RestaurantAdvertisement::query()
            ->live()
            ->forTarget(RestaurantAdvertisement::TARGET_RESTAURANT)
            ->whereColumn('target_id', 'restaurant_id')
            ->orderBy('start_at')
            ->pluck('restaurant_id')
            ->unique()
            ->values();
    }

    /**
     * Put featured restaurants first (in featured order) and add is_featured
     * to each row. Rows are arrays with an 'id' key.
     */
    public function featuredFirst(Collection $restaurants): Collection
    {
        $featuredIds = $this->featuredRestaurantIds()->flip();

        $rows = $restaurants->map(fn ($row) => $row + ['is_featured' => $featuredIds->has($row['id'])]);

        [$featured, $others] = $rows->partition(fn ($row) => $row['is_featured']);

        return $featured
            ->sortBy(fn ($row) => $featuredIds[$row['id']])
            ->concat($others)
            ->values();
    }

    public function formatForCustomer(RestaurantAdvertisement $advertisement): array
    {
        $data = [
            'id' => $advertisement->id,
            'type' => $advertisement->target_type,
            'image' => $advertisement->image,
            'image_url' => $advertisement->imageUrl(),
            'restaurant_id' => $advertisement->restaurant_id,
            'ends_at' => $advertisement->end_at,
        ];

        if ($advertisement->relationLoaded('restaurant') && $advertisement->restaurant) {
            $data['restaurant'] = [
                'id' => $advertisement->restaurant->id,
                'name' => $advertisement->restaurant->restaurant_name,
                'is_online' => (bool) $advertisement->restaurant->is_online,
            ];
        }

        if ($advertisement->target_type === RestaurantAdvertisement::TARGET_FOOD_ITEM) {
            $data['food_item_id'] = $advertisement->target_id;

            if ($advertisement->relationLoaded('foodItem') && $advertisement->foodItem) {
                $item = $advertisement->foodItem;
                $data['food_item'] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'price' => $item->price,
                    'discount_price' => $item->discount_price,
                    'offer_label' => $item->offer_label,
                    'image' => $item->image,
                    'category' => $item->category ? ['id' => $item->category->id, 'name' => $item->category->name] : null,
                ];
            }
        }

        return $data;
    }

    private function liveFoodItemQuery()
    {
        return RestaurantAdvertisement::query()
            ->live()
            ->forTarget(RestaurantAdvertisement::TARGET_FOOD_ITEM)
            // Food item must still belong to the advertiser and be on sale
            ->whereHas('foodItem', fn ($q) => $q
                ->whereColumn('restaurant_menu_items.restaurant_id', 'restaurant_advertisements.restaurant_id')
                ->where('is_active', true)
                ->where('is_available', true)
                ->whereHas('category', fn ($c) => $c->where('is_active', true)));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function toPaise(float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function label(RestaurantAdvertisement $advertisement): string
    {
        $name = $advertisement->package?->name ?? 'advertisement';

        if ($advertisement->target_type === RestaurantAdvertisement::TARGET_FOOD_ITEM && $advertisement->foodItem) {
            $name .= ' (' . $advertisement->foodItem->name . ')';
        }

        return $name;
    }

    /**
     * One audit row: the ad's status / payment status after the change,
     * who did it (system when no actor) and an optional note.
     *
     * @param array{type: string, id: ?int, name: ?string}|null $actor
     */
    private function record(
        RestaurantAdvertisement $advertisement,
        string $action,
        ?array $actor = null,
        ?string $fromStatus = null,
        ?string $note = null,
        array $meta = []
    ): void {
        RestaurantAdvertisementHistory::create([
            'advertisement_id' => $advertisement->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $advertisement->status,
            'payment_status' => $advertisement->payment_status,
            'actor_type' => $actor['type'] ?? RestaurantAdvertisementHistory::ACTOR_SYSTEM,
            'actor_id' => $actor['id'] ?? null,
            'actor_name' => $actor['name'] ?? null,
            'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
            'meta' => $meta ?: null,
        ]);
    }

    private function notifyActivated(RestaurantAdvertisement $advertisement): void
    {
        $advertisement->loadMissing(['package', 'foodItem']);

        $dates = $advertisement->start_at->format('d M Y h:i A') . ' - ' . $advertisement->end_at->format('d M Y h:i A');

        $this->notify(
            $advertisement,
            'Advertisement active',
            'Payment received. Your ' . $this->label($advertisement) . ' runs ' . $dates . '.'
        );
    }

    private function notify(RestaurantAdvertisement $advertisement, string $title, string $message): void
    {
        RestaurantNotification::create([
            'restaurant_id' => $advertisement->restaurant_id,
            'type' => 'promotion',
            'title' => $title,
            'message' => $message,
            'reference_type' => 'restaurant_advertisement',
            'reference_id' => $advertisement->id,
            'is_read' => false,
        ]);
    }
}
