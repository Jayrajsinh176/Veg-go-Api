<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurant_menu_items', function (Blueprint $table) {
            // Offer currently applied to discount_price (null = none)
            $table->foreignId('active_offer_id')
                ->nullable()
                ->after('discount_price')
                ->constrained('restaurant_offers')
                ->nullOnDelete();

            // Restaurant's own discount_price, restored when the offer ends
            $table->decimal('regular_discount_price', 10, 2)
                ->nullable()
                ->after('active_offer_id');

            // e.g. "20% OFF" / "₹30 OFF" for the customer app
            $table->string('offer_label', 50)
                ->nullable()
                ->after('regular_discount_price');
        });

        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->decimal('original_price', 10, 2)
                ->nullable()
                ->after('quantity');

            $table->foreignId('offer_id')
                ->nullable()
                ->after('original_price')
                ->constrained('restaurant_offers')
                ->nullOnDelete();

            $table->decimal('offer_discount', 10, 2)
                ->default(0)
                ->after('offer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offer_id');
            $table->dropColumn(['original_price', 'offer_discount']);
        });

        Schema::table('restaurant_menu_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_offer_id');
            $table->dropColumn(['regular_discount_price', 'offer_label']);
        });
    }
};
