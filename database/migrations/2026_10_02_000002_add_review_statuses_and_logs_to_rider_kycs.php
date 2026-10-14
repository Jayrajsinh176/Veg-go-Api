<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE rider_kycs MODIFY status ENUM('pending', 'under_review', 'info_required', 'verified', 'rejected') NOT NULL DEFAULT 'pending'"
        );

        Schema::create('rider_application_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rider_id')
                ->constrained('riders')
                ->cascadeOnDelete();

            $table->string('title');

            // Admin Action / Applicant Action
            $table->string('actor')->default('Admin Action');

            $table->timestamps();

            $table->index(['rider_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rider_application_logs');

        DB::statement(
            "UPDATE rider_kycs SET status = 'pending' WHERE status IN ('under_review', 'info_required')"
        );

        DB::statement(
            "ALTER TABLE rider_kycs MODIFY status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending'"
        );
    }
};
