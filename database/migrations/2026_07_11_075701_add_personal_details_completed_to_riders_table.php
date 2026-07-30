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
        $table->boolean('personal_details_completed')
            ->default(false)
            ->after('work_settings_completed');
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropColumn('personal_details_completed');
    });
}
};
