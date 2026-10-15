<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Inventory stock: units on hand per product and a ledger of every change (deliveries, usage, counts). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Units on hand, in the product's own unit. Can go below zero if services use more than was counted.
            $table->decimal('stock_quantity', 12, 2)->default(0)->after('unit_cost');
            // Flag the product as low once stock falls to this level; null = only warn when it runs out.
            $table->decimal('reorder_level', 12, 2)->nullable()->after('stock_quantity');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            // opening | restock | usage | return | adjustment
            $table->string('type', 16);
            // Signed: positive adds to stock, negative takes it away.
            $table->decimal('quantity', 12, 2);
            $table->decimal('balance_after', 12, 2);
            // The appointment item / walk-in item whose completed service used the stock.
            $table->nullableUlidMorphs('source');
            $table->string('note', 300)->nullable();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });

        // See docs/SUPABASE.md: every table is locked away from the Data API.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_movements ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['stock_quantity', 'reorder_level']);
        });
    }
};
