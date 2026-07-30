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
        $table->string('rc_book_document')
            ->nullable()
            ->after('puc_verified');

        $table->boolean('rc_book_verified')
            ->default(false)
            ->after('rc_book_document');

        $table->string('insurance_document')
            ->nullable()
            ->after('rc_book_verified');

        $table->boolean('insurance_verified')
            ->default(false)
            ->after('insurance_document');
    });
}

public function down(): void
{
    Schema::table('rider_kycs', function (Blueprint $table) {
        $table->dropColumn([
            'rc_book_document',
            'rc_book_verified',
            'insurance_document',
            'insurance_verified',
        ]);
    });
}
};
