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
        // One row per ad impression / click reported by the customer app
        Schema::create('restaurant_advertisement_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('advertisement_id')
                ->constrained('restaurant_advertisements')
                ->cascadeOnDelete();

            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            // impression | click
            $table->string('event', 20);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['advertisement_id', 'event', 'created_at'], 'ad_events_ad_event_time_index');
            $table->index(['event', 'created_at'], 'ad_events_event_time_index');
            $table->index(['customer_id', 'advertisement_id', 'event', 'created_at'], 'ad_events_dedupe_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_advertisement_events');
    }
};
