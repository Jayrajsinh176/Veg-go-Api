<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_documents', function (Blueprint $table) {
            $table->string('pan_image_path')->nullable()->after('pan_number');
            $table->string('gst_image_path')->nullable()->after('gstin_number');
            $table->string('fssai_image_path')->nullable()->after('fssai_number');
        });

        $now = now();
        DB::table('restaurant_outlet_types')->insertOrIgnore([
            [
                'name' => 'Category I',
                'code' => 'category_1',
                'description' => 'Has both FSSAI license and GST registration.',
                'gst_rule' => 'GSTIN and GST certificate are required.',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Category II',
                'code' => 'category_2',
                'description' => 'Has FSSAI license only, no GST registration.',
                'gst_rule' => 'GSTIN is not required for this outlet.',
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('restaurant_documents', function (Blueprint $table) {
            $table->dropColumn([
                'pan_image_path',
                'gst_image_path',
                'fssai_image_path',
            ]);
        });

        DB::table('restaurant_outlet_types')
            ->whereIn('code', ['category_1', 'category_2'])
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('restaurant_documents')
                    ->whereColumn('restaurant_documents.outlet_type_id', 'restaurant_outlet_types.id');
            })
            ->delete();
    }
};
