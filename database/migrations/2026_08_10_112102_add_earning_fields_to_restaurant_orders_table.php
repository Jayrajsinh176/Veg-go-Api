<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->decimal('commission_percentage', 5, 2)
                ->nullable()
                ->after('total_amount');

            $table->decimal('commission_amount', 10, 2)
                ->default(0)
                ->after('commission_percentage');

            $table->decimal('restaurant_earning', 10, 2)
                ->default(0)
                ->after('commission_amount');

            $table->enum('earning_status', [
                'pending',
                'credited',
                'reversed',
            ])
                ->default('pending')
                ->after('restaurant_earning');

            $table->timestamp('earning_credited_at')
                ->nullable()
                ->after('earning_status');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->dropColumn([
                'commission_percentage',
                'commission_amount',
                'restaurant_earning',
                'earning_status',
                'earning_credited_at',
            ]);
        });
    }
};