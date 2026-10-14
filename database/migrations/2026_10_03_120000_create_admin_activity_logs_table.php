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
        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            // Snapshot so the log stays readable if the admin is removed
            $table->string('admin_name')->nullable();
            $table->string('admin_email')->nullable();

            $table->string('action');
            $table->string('module');
            $table->string('target')->nullable();

            $table->string('field')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('change_summary')->nullable();

            $table->text('reason')->nullable();
            $table->text('details')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('source')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('path')->nullable();

            $table->string('session_id')->nullable();
            $table->string('request_id')->nullable();

            $table->enum('risk_level', ['Low', 'Medium', 'High'])->default('Low');

            $table->enum('review_status', [
                'Needs Review',
                'Reviewed',
                'Escalated',
            ])->default('Needs Review');

            $table->json('notes')->nullable();

            $table->timestamps();

            $table->index(['module', 'action']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
    }
};
