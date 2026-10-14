<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_number')->unique();
            $table->string('category', 100);
            $table->string('subject');
            $table->text('description');
            $table->enum('status', ['pending', 'in_progress', 'resolved', 'closed'])->default('pending');
            $table->text('admin_reply')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_support_tickets');
    }
};
