<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantWalletTransaction extends Model
{
    protected $fillable = [
        'restaurant_id',
        'settlement_id',
        'type',
        'amount',
        'balance_after',
        'title',
        'description',
        'reference_type',
        'reference_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'reference_id' => 'integer',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(RestaurantWeeklySettlement::class, 'settlement_id');
    }
}
