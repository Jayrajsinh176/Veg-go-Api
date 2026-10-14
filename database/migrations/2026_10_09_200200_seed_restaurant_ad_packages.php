<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Default packages and prices (before GST). Admin can change prices or
     * switch durations off later in restaurant_ad_package_durations.
     */
    private array $packages = [
        [
            'code' => 'HOME_SCREEN_SHOWCASE',
            'name' => 'Customer Home Screen Showcase',
            'description' => 'Show your promotional banner on the customer Home screen.',
            'placement' => 'home',
            'requires_image' => true,
            'prices' => [1 => 500, 3 => 1200, 7 => 2500, 15 => 4800, 30 => 9000],
        ],
        [
            'code' => 'FOOD_ITEM_PROMOTION',
            'name' => 'Food Item Promotion',
            'description' => 'Promote one of your food items to customers.',
            'placement' => 'food_item',
            'requires_image' => true,
            'prices' => [1 => 250, 3 => 700, 7 => 1500, 15 => 2900, 30 => 5500],
        ],
        [
            'code' => 'CHECKOUT_PAGE_OFFER',
            'name' => 'Checkout Page Offer',
            'description' => 'Show an offer to customers checking out from your restaurant.',
            'placement' => 'checkout',
            'requires_image' => true,
            'prices' => [1 => 300, 3 => 850, 7 => 1800, 15 => 3500, 30 => 6500],
        ],
        [
            'code' => 'FEATURED_RESTAURANT',
            'name' => 'Featured Restaurant',
            'description' => 'Appear in the Featured section at the top of the restaurant listing.',
            'placement' => 'restaurant',
            'requires_image' => false,
            'prices' => [1 => 500, 3 => 1200, 7 => 2500, 15 => 4800, 30 => 9000],
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        foreach ($this->packages as $order => $package) {
            DB::table('restaurant_ad_packages')->updateOrInsert(
                ['code' => $package['code']],
                [
                    'name' => $package['name'],
                    'description' => $package['description'],
                    'placement' => $package['placement'],
                    'requires_image' => $package['requires_image'],
                    'is_active' => true,
                    'display_order' => $order + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $packageId = DB::table('restaurant_ad_packages')
                ->where('code', $package['code'])
                ->value('id');

            foreach ($package['prices'] as $days => $price) {
                DB::table('restaurant_ad_package_durations')->updateOrInsert(
                    ['package_id' => $packageId, 'duration_days' => $days],
                    [
                        'price' => $price,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $codes = array_column($this->packages, 'code');

        $ids = DB::table('restaurant_ad_packages')->whereIn('code', $codes)->pluck('id');

        DB::table('restaurant_ad_package_durations')->whereIn('package_id', $ids)->delete();
        DB::table('restaurant_ad_packages')->whereIn('id', $ids)->delete();
    }
};
