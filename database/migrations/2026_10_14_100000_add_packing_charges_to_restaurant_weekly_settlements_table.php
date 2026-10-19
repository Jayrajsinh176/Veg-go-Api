<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_weekly_settlements', function (Blueprint $table) {
            $table->decimal('packing_charges', 12, 2)->default(0)->after('commission');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_weekly_settlements', function (Blueprint $table) {
            $table->dropColumn('packing_charges');
        });
    }
};

