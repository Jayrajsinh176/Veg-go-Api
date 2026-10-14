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
        // Audit trail of one advertisement: who did what and when
        Schema::create('restaurant_advertisement_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('advertisement_id')
                ->constrained('restaurant_advertisements')
                ->cascadeOnDelete();

            // created | updated | payment_started | payment_received | payment_failed |
            // marked_paid | activated | paused | resumed | cancellation_requested |
            // cancelled | refunded | expired
            $table->string('action', 40);

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('payment_status', 20)->nullable();

            // restaurant | admin | super_admin | gateway | system
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();

            $table->string('note', 500)->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['advertisement_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_advertisement_histories');
    }
};
