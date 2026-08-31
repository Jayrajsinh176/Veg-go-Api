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
      Schema::create('customer_notifications', function (Blueprint $table) {
    $table->id();

    $table->foreignId('customer_id')
        ->constrained('customers')
        ->cascadeOnDelete();

    $table->string('title');
    $table->text('message');

    $table->string('type')->nullable();

    $table->unsignedBigInteger('order_id')->nullable();

    $table->boolean('is_read')->default(false);

    $table->timestamps();

    $table->index('customer_id');
    $table->index('order_id');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');
    }
};
