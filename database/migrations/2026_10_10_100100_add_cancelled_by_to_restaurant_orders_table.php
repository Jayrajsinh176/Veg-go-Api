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
        Schema::table('restaurant_orders', function (Blueprint $table) {
            // customer | restaurant | admin | system (unpaid auto-expiry)
            $table->string('cancelled_by', 20)
                ->nullable()
                ->after('cancel_reason');
        });

        $this->backfill();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->dropColumn('cancelled_by');
        });
    }

    /**
     * Fill cancelled_by for existing cancelled orders from their status history.
     */
    private function backfill(): void
    {
        DB::table('restaurant_orders')
            ->where('order_status', 'cancelled')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    $history = DB::table('order_status_histories')
                        ->where('order_id', $order->id)
                        ->where('status', 'cancelled')
                        // Not the cancel itself, only a later payment note
                        ->where(function ($query) {
                            $query->whereNull('remarks')
                                ->orWhere('remarks', 'not like', 'Payment received after cancellation%');
                        })
                        ->orderByDesc('id')
                        ->first(['changed_by', 'remarks']);

                    if (!$history) {
                        continue;
                    }

                    // Customer cancels used to be saved as changed_by = system
                    $cancelledBy = str_starts_with((string) $history->remarks, 'Order cancelled by customer')
                        ? 'customer'
                        : $history->changed_by;

                    DB::table('restaurant_orders')
                        ->where('id', $order->id)
                        ->update(['cancelled_by' => $cancelledBy]);
                }
            });
    }
};
