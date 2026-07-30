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
        $table->foreignId('order_type_id')
            ->nullable()
            ->after('work_zone_id')
            ->constrained('order_types')
            ->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropForeign(['order_type_id']);
        $table->dropColumn('order_type_id');
    });
}
};
