<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantMenuItem extends Model
{
    protected $fillable = [
        'restaurant_id',
        'category_id',
        'name',
        'description',
        'price',
        'discount_price',
        'image',
        'is_available',
        'is_recommended',
        'is_active',
    ];

    /**
     * Restaurant
     */
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Category
     */
    public function category()
    {
        return $this->belongsTo(RestaurantCategory::class, 'category_id');
    }
}