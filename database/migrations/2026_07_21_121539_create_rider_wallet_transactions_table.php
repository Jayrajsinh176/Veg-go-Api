<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_wallet_transactions', function (Blueprint $table) {

            $table->id();

            $table->foreignId('rider_id')
                ->constrained('riders')
                ->cascadeOnDelete();

            $table->enum('type', [
                'credit',
                'debit',
            ]);

            $table->decimal('amount', 12, 2);

            $table->decimal('balance_after', 12, 2);

            $table->string('title');

            $table->text('description')->nullable();

            $table->string('reference_type')->nullable();

            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_wallet_transactions');
    }
};