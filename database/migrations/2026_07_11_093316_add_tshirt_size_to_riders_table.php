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
        $table->string('tshirt_size', 10)
            ->nullable()
            ->after('delivery_kit_id');
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropColumn('tshirt_size');
    });
}
};
