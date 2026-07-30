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
        $table->string('first_name')->nullable()->after('name');
        $table->string('last_name')->nullable()->after('first_name');

        $table->enum('gender', [
            'male',
            'female',
        ])->nullable()->after('last_name');
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropColumn([
            'first_name',
            'last_name',
            'gender',
        ]);
    });
}
};
