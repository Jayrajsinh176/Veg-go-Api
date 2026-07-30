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
    Schema::table('restaurant_documents', function (Blueprint $table) {

        $table->enum('status', [
            'pending',
            'approved',
            'rejected'
        ])->default('pending')->after('fssai_number');

        $table->text('rejection_reason')
              ->nullable()
              ->after('status');

        $table->timestamp('reviewed_at')
              ->nullable()
              ->after('rejection_reason');

        $table->foreignId('reviewed_by')
              ->nullable()
              ->after('reviewed_at')
              ->constrained('users')
              ->nullOnDelete();

    });
}

    /**
     * Reverse the migrations.
     */
  public function down(): void
{
    Schema::table('restaurant_documents', function (Blueprint $table) {

        $table->dropForeign(['reviewed_by']);

        $table->dropColumn([
            'status',
            'rejection_reason',
            'reviewed_at',
            'reviewed_by'
        ]);

    });
}
};
