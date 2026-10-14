<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderWeeklySettlement extends Model
{
    protected $fillable = [
        'rider_id',
        'week_start',
        'week_end',
        'total_amount',
        'opening_balance',
        'total_credit',
        'total_debit',
        'closing_balance',
        'cod_deducted',
        'payout_amount',
        'payout_date',
        'status',
        'payout_reference',
        'processed_at',
        'remarks',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'payout_date' => 'date',
        'total_amount' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'total_debit' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'cod_deducted' => 'decimal:2',
        'payout_amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(RiderWalletTransaction::class, 'settlement_id');
    }

    public function codSettlements(): HasMany
    {
        return $this->hasMany(RiderCodSettlement::class, 'settlement_id');
    }
}
