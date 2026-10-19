<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantWeeklySettlement extends Model
{
    protected $fillable = [
        'restaurant_id',
        'week_start',
        'week_end',
        'payout_date',
        'orders_count',
        'gross_sales',
        'commission',
        'packing_charges',
        'total_amount',
        'opening_balance',
        'total_credit',
        'total_debit',
        'closing_balance',
        'payout_amount',
        'status',
        'payout_reference',
        'processed_at',
        'remarks',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'payout_date' => 'date',
        'orders_count' => 'integer',
        'gross_sales' => 'decimal:2',
        'commission' => 'decimal:2',
        'packing_charges' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'total_debit' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'payout_amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(RestaurantWalletTransaction::class, 'settlement_id');
    }
}
