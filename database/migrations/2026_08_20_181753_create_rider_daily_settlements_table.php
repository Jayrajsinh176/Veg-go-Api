<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_weekly_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rider_id')
                ->constrained('riders')
                ->cascadeOnDelete();

            $table->date('week_start');

            $table->date('week_end');

            $table->decimal('total_amount', 12, 2)->default(0);

            $table->date('payout_date');

            $table->enum('status', [
                'pending',
                'processing',
                'successful',
                'failed'
            ])->default('pending');

            $table->string('payout_reference')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique([
                'rider_id',
                'week_start',
                'week_end'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_weekly_settlements');
    }
};