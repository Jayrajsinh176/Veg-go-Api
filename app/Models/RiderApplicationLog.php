<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderApplicationLog extends Model
{
    protected $fillable = [
        'rider_id',
        'title',
        'actor',
    ];

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }
}
