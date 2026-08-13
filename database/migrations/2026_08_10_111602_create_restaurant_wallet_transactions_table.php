<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->constrained('restaurants')
                ->cascadeOnDelete();

            $table->enum('type', [
                'credit',
                'debit',
            ]);

            $table->decimal('amount', 15, 2);

            $table->decimal('balance_after', 15, 2);

            $table->string('title');

            $table->text('description')->nullable();

            $table->string('reference_type')->nullable();

            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();

            $table->index([
                'reference_type',
                'reference_id',
            ]);

            $table->index([
                'restaurant_id',
                'created_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_wallet_transactions');
    }
};