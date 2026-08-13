<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
 public function up(): void
{
    Schema::table('restaurant_reviews', function (Blueprint $table) {
        $table->unique('restaurant_order_id');
    });
}

public function down(): void
{
    Schema::table('restaurant_reviews', function (Blueprint $table) {
        $table->dropUnique(['restaurant_order_id']);
    });
}
};
