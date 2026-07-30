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
    Schema::table('riders', function (Blueprint $table) {
        $table->foreignId('delivery_kit_id')
            ->nullable()
            ->after('order_type_id')
            ->constrained('delivery_kits')
            ->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropForeign(['delivery_kit_id']);
        $table->dropColumn('delivery_kit_id');
    });
}
};
