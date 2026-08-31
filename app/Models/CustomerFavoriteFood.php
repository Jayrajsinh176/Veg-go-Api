<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerFavoriteFood extends Model
{
    use HasFactory;

    protected $table = 'customer_favorite_foods';

    protected $fillable = [
        'customer_id',
        'menu_item_id',
    ];

    /**
     * Customer
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Menu Item
     */
    public function menuItem()
    {
        return $this->belongsTo(
            RestaurantMenuItem::class,
            'menu_item_id'
        );
    }
}