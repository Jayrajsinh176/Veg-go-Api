<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OrderRefund extends Model
{
    use HasFactory;

    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';

    public const SCENARIO_PRE_DELIVERY_CANCEL = 'pre_delivery_cancel';
    public const SCENARIO_POST_DELIVERY_COMPLAINT = 'post_delivery_complaint';

    public const METHOD_SOURCE_ACCOUNT = 'source_account';
    public const METHOD_COUPON = 'coupon';

    protected $fillable = [
        'order_id',
        'customer_id',
        'restaurant_id',
        'payment_id',
        'refund_number',
        'scenario',
        'refund_method',
        'subtotal',
        'refund_percentage',
        'amount',
        'reason',
        'customer_note',
        'proof_image',
        'coupon_id',
        'gateway_refund_id',
        'gateway_status',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'refund_percentage' => 'decimal:2',
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = [
        'proof_image_url',
    ];

    public function getProofImageUrlAttribute(): ?string
    {
        if (!$this->proof_image) {
            return null;
        }

        if (filter_var($this->proof_image, FILTER_VALIDATE_URL)) {
            return $this->proof_image;
        }

        return Storage::disk('public')->url($this->proof_image);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrderPayment::class, 'payment_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }
}

