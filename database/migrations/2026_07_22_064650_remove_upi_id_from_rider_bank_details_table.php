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
    Schema::table('rider_bank_details', function (Blueprint $table) {
        $table->dropColumn('upi_id');
    });
}

    /**
     * Reverse the migrations.
     */
   public function down(): void
{
    Schema::table('rider_bank_details', function (Blueprint $table) {
        $table->string('upi_id')->nullable()->after('branch_name');
    });
}
};
