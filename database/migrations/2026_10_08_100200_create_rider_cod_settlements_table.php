<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_cod_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rider_id')
                ->constrained('riders')
                ->cascadeOnDelete();

            $table->decimal('amount', 12, 2);

            // wallet: recovered from the rider wallet | cash: handed to admin directly
            $table->enum('method', ['wallet', 'cash']);

            // rider: settled from the app | auto_payout: deducted at weekly payout | admin: recorded by admin
            $table->enum('source', ['rider', 'auto_payout', 'admin']);

            $table->decimal('pending_cod_after', 12, 2);

            $table->foreignId('settlement_id')
                ->nullable()
                ->constrained('rider_weekly_settlements')
                ->nullOnDelete();

            $table->foreignId('wallet_transaction_id')
                ->nullable()
                ->constrained('rider_wallet_transactions')
                ->nullOnDelete();

            $table->string('settled_by_type')->nullable();
            $table->unsignedBigInteger('settled_by_id')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_cod_settlements');
    }
};
