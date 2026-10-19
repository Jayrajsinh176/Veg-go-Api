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
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('name')->nullable()->after('code');
            $table->text('description')->nullable()->after('name');
            $table->unsignedInteger('total_limit')->default(0)->after('max_discount_amount');
            $table->unsignedInteger('customer_limit')->default(1)->after('total_limit');
            $table->unsignedInteger('used_count')->default(0)->after('customer_limit');
            $table->string('applicable_type', 30)->default('all')->after('used_count');
            $table->json('applicable_restaurants')->nullable()->after('applicable_type');
            $table->json('applicable_categories')->nullable()->after('applicable_restaurants');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'description',
                'total_limit',
                'customer_limit',
                'used_count',
                'applicable_type',
                'applicable_restaurants',
                'applicable_categories',
            ]);
        });
    }
};

