<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantReviewReply extends Model
{
    protected $fillable = [
        'restaurant_review_id',
        'restaurant_id',
        'reply',
    ];

    public function review()
    {
        return $this->belongsTo(RestaurantReview::class, 'restaurant_review_id');
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}