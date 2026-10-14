<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantReview extends Model
{
    protected $fillable = [
        'restaurant_id',
        'restaurant_order_id',
        'customer_id',
        'rating',
        'review',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function order()
    {
        return $this->belongsTo(RestaurantOrder::class, 'restaurant_order_id');
    }

    public function reply()
    {
        return $this->hasOne(RestaurantReviewReply::class, 'restaurant_review_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}