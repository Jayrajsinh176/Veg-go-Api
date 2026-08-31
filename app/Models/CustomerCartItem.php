<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerCartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'menu_item_id',
        'menu_item_name',
        'quantity',
        'unit_price',
        'total_price',
        'special_instruction',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    /**
     * Cart
     */
    public function cart()
    {
        return $this->belongsTo(CustomerCart::class, 'cart_id');
    }

    /**
     * Menu Item
     */
    public function menuItem()
    {
        return $this->belongsTo(RestaurantMenuItem::class, 'menu_item_id');
    }
}