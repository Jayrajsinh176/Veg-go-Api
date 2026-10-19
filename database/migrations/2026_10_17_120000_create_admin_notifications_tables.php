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
        // One row per event, shared by every admin who may see it
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();

            // restaurant_application | rider_application | support_request |
            // order_issue | cancellation | payment_issue | system
            $table->string('type', 40);

            $table->string('title');
            $table->text('message');

            // Permission module needed to see it (config/admin_permissions.php); NULL = everyone
            $table->string('module', 50)->nullable();

            // What "Open Record" opens, e.g. restaurant / 42 / "Green Leaf Grill"
            $table->string('record_type', 40)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('record_ref', 100)->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index(['record_type', 'record_id']);
            $table->index('created_at');
        });

        // Read state is per admin
        Schema::create('admin_notification_reads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admin_notification_id')
                ->constrained('admin_notifications')
                ->cascadeOnDelete();

            $table->foreignId('admin_id')
                ->constrained('admins')
                ->cascadeOnDelete();

            $table->timestamp('read_at')->useCurrent();

            $table->unique(['admin_notification_id', 'admin_id'], 'admin_notification_reads_unique');
            $table->index('admin_id');
        });

        // Which notification types this admin wants to see
        Schema::table('admins', function (Blueprint $table) {
            $table->json('notification_settings')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('notification_settings');
        });

        Schema::dropIfExists('admin_notification_reads');
        Schema::dropIfExists('admin_notifications');
    }
};
