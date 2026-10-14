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
        Schema::create('restaurant_advertisements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->constrained('restaurants')
                ->cascadeOnDelete();

            $table->foreignId('package_id')
                ->constrained('restaurant_ad_packages');

            $table->foreignId('package_duration_id')
                ->constrained('restaurant_ad_package_durations');

            // home | food_item | checkout | restaurant
            $table->string('target_type', 20);

            // food item id for food_item, restaurant id for restaurant, else NULL
            $table->unsignedBigInteger('target_id')->nullable();

            // Path on the public disk (restaurant_ads/...)
            $table->string('image')->nullable();

            $table->unsignedInteger('duration_days');

            // Copied from the package duration when the ad is created/paid
            $table->decimal('amount', 10, 2);
            $table->decimal('tax_percentage', 5, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);

            // pending | paid | failed | refunded
            $table->string('payment_status', 20)->default('pending');

            // payu | manual | ...
            $table->string('payment_method', 30)->nullable();

            // Gateway payment id of the successful payment
            $table->string('payment_reference')->nullable()->unique();

            // draft | pending | active | paused | expired | cancelled
            $table->string('status', 20)->default('draft');

            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();

            // restaurant | admin | super_admin
            $table->string('created_by_type', 20);
            $table->unsignedBigInteger('created_by_id')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by_admin_id')->nullable();

            // Restaurant may ask to cancel a paid ad; admin decides
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_type', 20)->nullable();
            $table->unsignedBigInteger('cancelled_by_id')->nullable();

            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index(['status', 'payment_status', 'start_at', 'end_at'], 'restaurant_ads_live_index');
            $table->index(['target_type', 'target_id']);
        });

        // One row per gateway order (a restaurant may retry after a failed payment)
        Schema::create('restaurant_ad_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('advertisement_id')
                ->constrained('restaurant_advertisements')
                ->cascadeOnDelete();

            $table->string('gateway', 30);

            $table->string('gateway_order_id')->unique();

            // Unique so the same payment can never be applied twice
            $table->string('gateway_payment_id')->nullable()->unique();

            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('INR');

            // created | paid | failed
            $table->string('status', 20)->default('created');

            // callback | webhook | admin
            $table->string('verified_via', 20)->nullable();

            $table->string('failure_reason', 500)->nullable();

            $table->json('gateway_response')->nullable();

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_ad_payments');
        Schema::dropIfExists('restaurant_advertisements');
    }
};
