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
        $table->foreignId('work_zone_id')
            ->nullable()
            ->after('vehicle_type_selected_at')
            ->constrained('work_zones')
            ->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropForeign(['work_zone_id']);
        $table->dropColumn('work_zone_id');
    });
}
};
