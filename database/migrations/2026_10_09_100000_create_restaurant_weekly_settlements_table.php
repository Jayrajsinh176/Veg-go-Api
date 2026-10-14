<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_weekly_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->constrained('restaurants')
                ->cascadeOnDelete();

            // Monday -> Sunday, paid out on payout_date (Tuesday) morning
            $table->date('week_start');
            $table->date('week_end');
            $table->date('payout_date');

            // Delivered orders credited in the cycle (net of reversals)
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('gross_sales', 12, 2)->default(0);
            $table->decimal('commission', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            // Wallet snapshot taken at payout
            $table->decimal('opening_balance', 12, 2)->nullable();
            $table->decimal('total_credit', 12, 2)->nullable();
            $table->decimal('total_debit', 12, 2)->nullable();
            $table->decimal('closing_balance', 12, 2)->nullable();

            $table->decimal('payout_amount', 12, 2)->default(0);

            $table->enum('status', [
                'pending',
                'processing',
                'successful',
                'failed',
            ])->default('pending');

            $table->string('payout_reference')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(['restaurant_id', 'week_start', 'week_end'], 'rws_restaurant_week_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_weekly_settlements');
    }
};
