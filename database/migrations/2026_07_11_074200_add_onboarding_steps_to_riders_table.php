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
        $table->boolean('work_settings_completed')
            ->default(false)
            ->after('onboarding_completed');

        $table->boolean('profile_completed')
            ->default(false)
            ->after('work_settings_completed');

        $table->boolean('delivery_kit_completed')
            ->default(false)
            ->after('profile_completed');
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropColumn([
            'work_settings_completed',
            'profile_completed',
            'delivery_kit_completed',
        ]);
    });
}
};
