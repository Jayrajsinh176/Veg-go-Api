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
        $table->decimal('latitude', 10, 7)->nullable()
            ->after('last_online_at');

        $table->decimal('longitude', 10, 7)->nullable()
            ->after('latitude');

        $table->decimal('heading', 8, 2)->nullable()
            ->after('longitude');

        $table->decimal('speed', 8, 2)->nullable()
            ->after('heading');

        $table->timestamp('last_location_at')->nullable()
            ->after('speed');
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropColumn([
            'latitude',
            'longitude',
            'heading',
            'speed',
            'last_location_at',
        ]);
    });
}
};
