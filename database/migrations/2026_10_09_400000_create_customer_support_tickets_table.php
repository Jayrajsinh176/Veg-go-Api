<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('restaurant_orders')->nullOnDelete();
            $table->string('ticket_number')->unique();
            $table->string('category', 100);
            $table->string('subject');
            $table->text('description');
            $table->enum('status', ['pending', 'in_progress', 'replied', 'resolved', 'closed'])->default('pending');
            $table->text('admin_reply')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_support_tickets');
    }
};

