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
    Schema::create('coupons', function (Blueprint $table) {
        $table->id();
        $table->string('code', 100)->unique();
        $table->enum('discount_type', ['percentage', 'fixed']);
        $table->decimal('discount_value', 10, 2);
        $table->decimal('min_order_amount', 10, 2)->default(0);
        $table->decimal('max_discount_amount', 10, 2)->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamp('starts_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
