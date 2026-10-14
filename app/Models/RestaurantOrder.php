<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantOrder extends Model
{
    use HasFactory;

protected $fillable = [
    'restaurant_id',
    'customer_id',
    'delivery_address_id',
    'rider_id',
    'pickup_otp',
    'pickup_otp_generated_at',
    'pickup_verified_at',
        'delivery_otp',
    'delivery_otp_expires_at',
    'order_number',
    'subtotal',
    'delivery_charge',
    'packing_charge',
    'platform_fee',
    'small_order_fee',
    'tax',
    'discount',
    'total_amount',

    'commission_percentage',
    'commission_amount',
    'restaurant_earning',
    'earning_status',
    'earning_credited_at',

    'payment_method',
    'payment_status',
    'order_status',
    'placed_at',
    'accepted_at',
    'preparing_at',
    'ready_for_pickup_at',
    'picked_up_at',
    'delivered_at',
    'cancelled_at',
    'cancel_reason',
    'cancelled_by',
    'notes',
];

protected $casts = [
    'commission_percentage' => 'decimal:2',
    'commission_amount' => 'decimal:2',
    'restaurant_earning' => 'decimal:2',
    'earning_credited_at' => 'datetime',

      'pickup_otp_generated_at' => 'datetime',
    'pickup_verified_at' => 'datetime',

    'delivery_otp_expires_at' => 'datetime',

   
];

    /**
     * Restaurant
     */
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function customer()
{
    return $this->belongsTo(Customer::class);
}

    /**
 * Delivery Address
 */
public function deliveryAddress()
{
    return $this->belongsTo(CustomerAddress::class, 'delivery_address_id');
}

    /**
     * Assigned Rider
     */
    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }

    /**
     * Order Items
     */
    public function items()
    {
        return $this->hasMany(RestaurantOrderItem::class, 'order_id');
    }

    /**
     * Status History
     */
   public function statusHistories()
{
    return $this->hasMany(OrderStatusHistory::class, 'order_id');
}

    /**
     * Payment
     */
    public function payment()
    {
        return $this->hasOne(RestaurantOrderPayment::class, 'order_id');
    }

    /**
     * Review submitted for this order
     */
    public function review()
    {
        return $this->hasOne(RestaurantReview::class, 'restaurant_order_id');
    }

    /**
     * Orders a restaurant may see and act on:
     * cash orders, or online orders whose payment is confirmed.
     */
    public function scopeVisibleToRestaurant($query)
    {
        return $query->where(function ($q) {
            $q->where('payment_method', 'cash')
                ->orWhere('payment_status', 'paid');
        });
    }
}