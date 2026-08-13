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
    Schema::create('restaurant_notifications', function (Blueprint $table) {
        $table->id();

        $table->foreignId('restaurant_id')
            ->constrained('restaurants')
            ->cascadeOnDelete();

        $table->enum('type', [
            'order',
            'payment',
            'review',
            'menu',
            'promotion',
            'system'
        ]);

        $table->string('title');
        $table->text('message');

        $table->string('reference_type')->nullable();
        $table->unsignedBigInteger('reference_id')->nullable();

        $table->boolean('is_read')->default(false);

        $table->timestamp('read_at')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_notifications');
    }
};
