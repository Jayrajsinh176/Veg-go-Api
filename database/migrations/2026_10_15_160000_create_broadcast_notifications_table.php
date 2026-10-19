<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('broadcast_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->nullable(); // e.g. NOTIF-01
            $table->string('title');
            $table->text('message');
            $table->string('audience'); // Customers, Restaurants, Delivery Partners, All Active Users
            $table->json('channels')->nullable(); // ["push", "inApp", "sms", "email"]
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status')->default('Delivered'); // Delivered, Scheduled, Draft, Failed
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcast_notifications');
    }
};

