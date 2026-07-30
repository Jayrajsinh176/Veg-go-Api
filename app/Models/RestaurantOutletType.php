<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOutletType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'gst_rule',
        'is_active',
        'sort_order',
    ];

    public function restaurantDocuments()
{
    return $this->hasMany(RestaurantDocument::class, 'outlet_type_id');
}

}