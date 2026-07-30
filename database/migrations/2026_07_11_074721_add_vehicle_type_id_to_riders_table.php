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
        $table->foreignId('vehicle_type_id')
            ->nullable()
            ->after('city_id')
            ->constrained('vehicle_types')
            ->nullOnDelete();

        $table->timestamp('vehicle_type_selected_at')
            ->nullable()
            ->after('vehicle_type_id');
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropForeign(['vehicle_type_id']);

        $table->dropColumn([
            'vehicle_type_id',
            'vehicle_type_selected_at',
        ]);
    });
}
};
