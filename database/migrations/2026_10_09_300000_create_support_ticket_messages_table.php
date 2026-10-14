<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->enum('ticket_type', ['rider', 'restaurant']);
            $table->unsignedBigInteger('ticket_id');
            $table->enum('sender_type', ['user', 'admin']);
            $table->text('message')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->index(['ticket_type', 'ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
    }
};
