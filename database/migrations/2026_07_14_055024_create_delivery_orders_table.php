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
    Schema::create('delivery_orders', function (Blueprint $table) {
        $table->id();

        $table->string('order_number')->unique();

        $table->string('restaurant_name');

        $table->decimal('pickup_latitude', 10, 7);
        $table->decimal('pickup_longitude', 10, 7);

        $table->decimal('delivery_latitude', 10, 7);
        $table->decimal('delivery_longitude', 10, 7);

        $table->decimal('rider_earning', 10, 2)->default(0);

        $table->foreignId('rider_id')
            ->nullable()
            ->constrained('riders')
            ->nullOnDelete();

        $table->enum('status', [
            'available',
            'accepted',
            'arrived',
            'picked_up',
            'delivered',
            'cancelled',
        ])->default('available');

        $table->string('restaurant_status', 50)->nullable();

        $table->timestamps();

        $table->index('status');
        $table->index(['pickup_latitude', 'pickup_longitude']);
    });
}

public function down(): void
{
    Schema::dropIfExists('delivery_orders');
}
};
