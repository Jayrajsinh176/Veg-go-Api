<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description', 500)->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_built_in')->default(false);
            $table->timestamps();
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->nullable()
                ->after('role')
                ->constrained('admin_roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('admin_roles');
    }
};
