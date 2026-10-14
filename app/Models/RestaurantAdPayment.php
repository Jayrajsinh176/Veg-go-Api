<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantAdPayment extends Model
{
    public const STATUS_CREATED = 'created';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'advertisement_id',
        'gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'amount',
        'currency',
        'status',
        'verified_via',
        'failure_reason',
        'gateway_response',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
    ];

    public function advertisement()
    {
        return $this->belongsTo(RestaurantAdvertisement::class, 'advertisement_id');
    }
}
