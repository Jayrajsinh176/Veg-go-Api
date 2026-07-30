<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderWithdrawal extends Model
{
    protected $fillable = [
        'rider_id',
        'amount',
        'withdraw_method',
        'bank_detail_id',
        'upi_detail_id',
        'status',
        'remarks',
        'approved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }

    public function bankDetail()
    {
        return $this->belongsTo(RiderBankDetail::class);
    }

    public function upiDetail()
    {
        return $this->belongsTo(RiderUpiDetail::class);
    }
}