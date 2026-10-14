<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();

            // 1. Platform & Business Identity
            $table->string('platform_name')->default('VegGo');
            $table->string('support_email')->default('support@veggo.com');
            $table->string('support_phone')->default('+91 98765 43210');
            $table->string('website')->nullable()->default('https://veggo.com');
            $table->string('company_name')->default('VegGo Technologies Pvt. Ltd.');
            $table->string('legal_name')->default('VegGo Technologies Private Limited');
            $table->string('gstin')->nullable()->default('24ABCDE1234F1Z5');
            $table->string('cin')->nullable()->default('U72900GJ2026PTC123456');
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();

            // 2. Operations & Order Rules
            $table->boolean('maintenance_mode')->default(false);
            $table->boolean('auto_accept_orders')->default(false);
            $table->boolean('order_cancellation')->default(true);
            $table->unsignedInteger('cancellation_window')->default(5);
            $table->decimal('minimum_order_value', 10, 2)->default(99.00);
            $table->unsignedInteger('max_orders_per_restaurant')->default(100);
            $table->string('order_id_prefix')->default('VZG');

            // 3. Delivery & Platform Pricing
            $table->decimal('base_delivery_fee', 10, 2)->default(25.00);
            $table->decimal('per_km_fee', 10, 2)->default(7.00);
            $table->decimal('free_delivery_above', 10, 2)->default(499.00);
            $table->decimal('max_delivery_radius', 10, 2)->default(12.00);
            $table->decimal('platform_fee', 10, 2)->default(5.00);
            $table->decimal('restaurant_commission', 5, 2)->default(30.00);

            // 4. Payments & Taxes
            $table->boolean('cod_enabled')->default(true);
            $table->boolean('online_payment_enabled')->default(true);
            $table->boolean('tax_inclusive')->default(true);
            $table->decimal('gst_rate', 5, 2)->default(5.00);
            $table->string('invoice_prefix')->default('VZG-INV');
            $table->string('hsn_code')->default('996331');

            $table->timestamps();
        });

        // Insert default row
        DB::table('platform_settings')->insert([
            'id' => 1,
            'platform_name' => 'VegGo',
            'support_email' => 'support@veggo.com',
            'support_phone' => '+91 98765 43210',
            'website' => 'https://veggo.com',
            'company_name' => 'VegGo Technologies Pvt. Ltd.',
            'legal_name' => 'VegGo Technologies Private Limited',
            'gstin' => '24ABCDE1234F1Z5',
            'cin' => 'U72900GJ2026PTC123456',
            'address' => 'Bharuch, Gujarat, India',
            'logo_path' => null,
            'maintenance_mode' => false,
            'auto_accept_orders' => false,
            'order_cancellation' => true,
            'cancellation_window' => 5,
            'minimum_order_value' => 99.00,
            'max_orders_per_restaurant' => 100,
            'order_id_prefix' => 'VZG',
            'base_delivery_fee' => 25.00,
            'per_km_fee' => 7.00,
            'free_delivery_above' => 499.00,
            'max_delivery_radius' => 12.00,
            'platform_fee' => 5.00,
            'restaurant_commission' => 30.00,
            'cod_enabled' => true,
            'online_payment_enabled' => true,
            'tax_inclusive' => true,
            'gst_rate' => 5.00,
            'invoice_prefix' => 'VZG-INV',
            'hsn_code' => '996331',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};

