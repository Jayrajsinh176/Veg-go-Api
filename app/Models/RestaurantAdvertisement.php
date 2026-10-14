<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class RestaurantAdvertisement extends Model
{
    // Advertisement status
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    // Payment status (kept separate from the ad status)
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';

    // target_type values
    public const TARGET_HOME = 'home';
    public const TARGET_FOOD_ITEM = 'food_item';
    public const TARGET_CHECKOUT = 'checkout';
    public const TARGET_RESTAURANT = 'restaurant';

    public const CREATED_BY_RESTAURANT = 'restaurant';
    public const CREATED_BY_ADMIN = 'admin';
    public const CREATED_BY_SUPER_ADMIN = 'super_admin';

    /*
     * Only RestaurantAdvertisementService writes these. Controllers must
     * never pass request input straight into create()/update().
     */
    protected $fillable = [
        'restaurant_id',
        'package_id',
        'package_duration_id',
        'target_type',
        'target_id',
        'image',
        'duration_days',
        'amount',
        'tax_percentage',
        'tax_amount',
        'total_amount',
        'payment_status',
        'payment_method',
        'payment_reference',
        'status',
        'start_at',
        'end_at',
        'created_by_type',
        'created_by_id',
        'paid_at',
        'paid_by_admin_id',
        'cancellation_requested_at',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by_type',
        'cancelled_by_id',
        'refunded_at',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancellation_requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function package()
    {
        return $this->belongsTo(RestaurantAdPackage::class, 'package_id');
    }

    public function packageDuration()
    {
        return $this->belongsTo(RestaurantAdPackageDuration::class, 'package_duration_id');
    }

    /**
     * Only meaningful when target_type = food_item.
     */
    public function foodItem()
    {
        return $this->belongsTo(RestaurantMenuItem::class, 'target_id');
    }

    public function payments()
    {
        return $this->hasMany(RestaurantAdPayment::class, 'advertisement_id');
    }

    public function histories()
    {
        return $this->hasMany(RestaurantAdvertisementHistory::class, 'advertisement_id');
    }

    /**
     * Payment states an active ad may be shown with. While online payment
     * is switched off (config advertisement.payment_enabled), unpaid
     * "pay later" ads are shown too.
     */
    public static function livePaymentStatuses(): array
    {
        return config('advertisement.payment_enabled', true)
            ? [self::PAYMENT_PAID]
            : [self::PAYMENT_PAID, self::PAYMENT_PENDING];
    }

    /**
     * Ads a customer may see right now: paid, active, inside the paid
     * window, and the restaurant is still approved. Paused, expired,
     * cancelled and unpaid ads never match.
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereIn('payment_status', self::livePaymentStatuses())
            ->where('start_at', '<=', $now)
            ->where('end_at', '>', $now)
            ->whereHas('restaurant', fn ($q) => $q->where('status', 'approved'));
    }

    public function scopeForTarget(Builder $query, string $type, ?int $id = null): Builder
    {
        $query->where('target_type', $type);

        return $id === null ? $query : $query->where('target_id', $id);
    }

    /**
     * Status for display: an active ad paid ahead of time shows as
     * "scheduled", and one past end_at shows "expired" even before the
     * scheduler has run.
     */
    public function displayStatus(): string
    {
        if ($this->status === self::STATUS_ACTIVE) {
            if ($this->end_at && $this->end_at->lte(now())) {
                return self::STATUS_EXPIRED;
            }

            if ($this->start_at && $this->start_at->gt(now())) {
                return 'scheduled';
            }
        }

        return $this->status;
    }

    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }
}
