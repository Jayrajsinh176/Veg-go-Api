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
    Schema::table('rider_kycs', function (Blueprint $table) {
        $table->string('aadhaar_document')->nullable()
            ->after('aadhaar_verified');

        $table->string('pan_document')->nullable()
            ->after('pan_verified');

        $table->text('driving_license_number')->nullable()
            ->after('pan_document');

        $table->string('driving_license_document')->nullable()
            ->after('driving_license_number');

        $table->boolean('driving_license_verified')
            ->default(false)
            ->after('driving_license_document');

        $table->string('puc_document')->nullable()
            ->after('driving_license_verified');

        $table->boolean('puc_verified')
            ->default(false)
            ->after('puc_document');
    });
}

public function down(): void
{
    Schema::table('rider_kycs', function (Blueprint $table) {
        $table->dropColumn([
            'aadhaar_document',
            'pan_document',
            'driving_license_number',
            'driving_license_document',
            'driving_license_verified',
            'puc_document',
            'puc_verified',
        ]);
    });
}
};
