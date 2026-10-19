<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An impression or click on a restaurant advertisement, reported by the
 * customer app. Written only by RestaurantAdvertisementService.
 */
class RestaurantAdvertisementEvent extends Model
{
    public const EVENT_IMPRESSION = 'impression';
    public const EVENT_CLICK = 'click';

    public const UPDATED_AT = null;

    protected $fillable = [
        'advertisement_id',
        'customer_id',
        'event',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function events(): array
    {
        return [self::EVENT_IMPRESSION, self::EVENT_CLICK];
    }

    public function advertisement()
    {
        return $this->belongsTo(RestaurantAdvertisement::class, 'advertisement_id');
    }
}
