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
    Schema::create('rider_support_tickets', function (Blueprint $table) {
        $table->id();

        $table->foreignId('rider_id')
            ->constrained('riders')
            ->cascadeOnDelete();

        $table->string('ticket_number')->unique();

        $table->string('category');

        $table->string('subject');

        $table->text('description');

        $table->enum('status', [
            'pending',
            'in_progress',
            'resolved',
            'closed'
        ])->default('pending');

        $table->text('admin_reply')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rider_support_tickets');
    }
};
