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
        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();

            // Order, Customer, Restaurant & Payment links
            $table->foreignId('order_id')->constrained('restaurant_orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('restaurant_order_payments')->nullOnDelete();

            $table->string('refund_number', 50)->unique(); // e.g. RF-9021

            // Scenario & Method
            $table->enum('scenario', ['pre_delivery_cancel', 'post_delivery_complaint']);
            $table->enum('refund_method', ['source_account', 'coupon']);

            // Amounts
            $table->decimal('subtotal', 10, 2)->default(0.00); // Food subtotal only
            $table->decimal('refund_percentage', 5, 2)->nullable(); // 20.00 for complaint, 100.00 for cancel
            $table->decimal('amount', 10, 2); // Calculated refund amount

            // Complaint details
            $table->string('reason');
            $table->text('customer_note')->nullable();
            $table->string('proof_image')->nullable(); // Mandatory for post_delivery_complaint

            // Coupon link (for complaint compensation)
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();

            // Payment gateway details (for PayU direct source refund)
            $table->string('gateway_refund_id', 100)->nullable();
            $table->string('gateway_status', 50)->nullable();

            // Review status
            $table->enum('status', ['under_review', 'processing', 'completed', 'rejected'])->default('under_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['restaurant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
    }
};
