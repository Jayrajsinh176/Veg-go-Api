<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderDailySettlement extends Model
{
    protected $fillable = [
        'rider_id',
        'settlement_date',
        'total_amount',
        'payout_date',
        'status',
        'payout_reference',
        'processed_at',
        'remarks',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'payout_date' => 'date',
        'total_amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }
}