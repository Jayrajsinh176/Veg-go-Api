<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Max delivery radius is now 15 km (previously hardcoded in the order flow,
     * now read from platform settings so super admin can change it).
     */
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->decimal('max_delivery_radius', 10, 2)->default(15.00)->change();
        });

        // Only move the old default; keep any value super admin already customised.
        DB::table('platform_settings')
            ->where('max_delivery_radius', 12.00)
            ->update(['max_delivery_radius' => 15.00, 'updated_at' => now()]);

        PlatformSetting::clearCache();
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->decimal('max_delivery_radius', 10, 2)->default(12.00)->change();
        });

        DB::table('platform_settings')
            ->where('max_delivery_radius', 15.00)
            ->update(['max_delivery_radius' => 12.00, 'updated_at' => now()]);

        PlatformSetting::clearCache();
    }
};
