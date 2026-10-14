<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Review state of a restaurant onboarding application
     * (Super Admin > Restaurant Applications)
     */
    public function up(): void
    {
        Schema::create('restaurant_application_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->unique()
                ->constrained('restaurants')
                ->cascadeOnDelete();

            $table->foreignId('assigned_admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            // Keys of the documents an admin has checked: fssai, gst, pan, bank
            $table->json('verified_documents')->nullable();

            // Set while the restaurant owes us more information
            $table->timestamp('info_requested_at')->nullable();

            $table->timestamp('restaurant_setup_verified_at')->nullable();
            $table->timestamp('menu_setup_verified_at')->nullable();
            $table->timestamp('live_verified_at')->nullable();

            $table->timestamps();
        });

        Schema::create('restaurant_application_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->constrained('restaurants')
                ->cascadeOnDelete();

            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            $table->string('admin_name')->nullable();

            // note: internal admin note | info_request: sent to the restaurant
            $table->enum('type', ['note', 'info_request']);

            $table->text('message');

            $table->timestamps();

            $table->index(['restaurant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_application_notes');
        Schema::dropIfExists('restaurant_application_reviews');
    }
};
