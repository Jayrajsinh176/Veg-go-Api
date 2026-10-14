<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Restaurant Commission
    |--------------------------------------------------------------------------
    |
    | VegGo currently charges 30% commission by default.
    | Restaurant receives the remaining 70%.
    | Admin can set a different rate per restaurant (restaurants.commission_percentage).
    |
    */

    'commission_percentage' => 30.00,

    /*
    |--------------------------------------------------------------------------
    | Delivery Charge
    |--------------------------------------------------------------------------
    |
    | Flat delivery fee (in rupees) added to every customer cart.
    |
    */

    'delivery_charge' => 9,

];