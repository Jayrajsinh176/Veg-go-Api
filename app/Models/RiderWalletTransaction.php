<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderWalletTransaction extends Model
{
    protected $fillable = [
        'rider_id',
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
    ];

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }

    public function settlement()
    {
        return $this->belongsTo(RiderWeeklySettlement::class, 'settlement_id');
    }
}