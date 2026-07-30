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
        Schema::create('restaurant_documents', function (Blueprint $table) {

            $table->id();

            $table->foreignId('restaurant_id')
                  ->constrained('restaurants')
                  ->cascadeOnDelete();

            $table->foreignId('outlet_type_id')
                  ->constrained('restaurant_outlet_types')
                  ->restrictOnDelete();

            $table->string('pan_number')->nullable();

            $table->string('gstin_number')->nullable();

            $table->string('upi_id')->nullable();

            $table->string('upi_holder_name')->nullable();

            $table->string('fssai_number')->nullable();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_documents');
    }
};