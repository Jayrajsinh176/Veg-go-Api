<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'menu_item_name',
        'variant_name',
        'quantity',
        'original_price',
        'offer_id',
        'offer_discount',
        'unit_price',
        'total_price',
        'special_instruction',
    ];

    /**
     * Order
     */
    public function order()
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }

    /**
     * Admin offer applied when the order was placed
     */
    public function offer()
    {
        return $this->belongsTo(RestaurantOffer::class, 'offer_id');
    }

    /**
     * Menu Item
     */
    public function menuItem()
    {
        return $this->belongsTo(RestaurantMenuItem::class, 'menu_item_id');
    }
}