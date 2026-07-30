<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerContractTerm extends Model
{
    protected $fillable = [
        'title',
        'content',
        'display_order',
        'is_active',
    ];
}