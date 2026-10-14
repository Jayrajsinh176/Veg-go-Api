<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('admin_name')->nullable();
            $table->string('admin_email')->nullable();
            $table->string('action');
            $table->string('module');
            $table->string('target')->nullable();
            $table->string('method', 10);
            $table->string('path');
            $table->text('details');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['module', 'action']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_activity_events');
    }
};
