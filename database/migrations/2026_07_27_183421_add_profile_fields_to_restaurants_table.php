<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {

            $table->string('owner_name')->nullable()->after('mobile');
            $table->string('restaurant_name')->nullable()->after('owner_name');
            $table->string('email')->nullable()->after('restaurant_name');
            $table->string('whatsapp_number')->nullable()->after('email');
            $table->boolean('is_whatsapp_same')->default(true)->after('whatsapp_number');
            $table->boolean('same_time_all_days')->default(true)->after('is_whatsapp_same');

        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {

            $table->dropColumn([
                'owner_name',
                'restaurant_name',
                'email',
                'whatsapp_number',
                'is_whatsapp_same',
                'same_time_all_days',
            ]);

        });
    }
};