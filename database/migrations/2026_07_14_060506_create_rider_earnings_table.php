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
    Schema::create('rider_earnings', function (Blueprint $table) {
        $table->id();

        $table->foreignId('rider_id')
            ->constrained('riders')
            ->cascadeOnDelete();

        $table->foreignId('delivery_order_id')
            ->nullable()
            ->constrained('delivery_orders')
            ->nullOnDelete();

        $table->enum('type', [
            'delivery',
            'bonus',
            'incentive',
            'adjustment',
        ]);

        $table->decimal('amount', 12, 2);

        $table->string('title');
        $table->text('description')->nullable();

        $table->timestamps();

        $table->index(['rider_id', 'created_at']);
        $table->index('type');
    });
}

public function down(): void
{
    Schema::dropIfExists('rider_earnings');
}
};
