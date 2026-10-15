<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Inventory: products the salon buys, which services use them, and the cost recorded per completed service. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('brand', 120)->nullable();
            // What one unit is, e.g. "pc", "ml", "bottle". The service usage quantity is counted in this unit.
            $table->string('unit', 30);
            $table->decimal('unit_cost', 10, 2);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_service', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 8, 2);
            $table->timestamps();
            $table->primary(['product_id', 'service_id']);
        });

        // Snapshot taken when the service is completed, so later unit-cost changes keep past net revenue intact.
        Schema::table('appointment_items', function (Blueprint $table) {
            $table->decimal('product_cost', 10, 2)->default(0)->after('subtotal');
        });
        Schema::table('walk_in_visit_items', function (Blueprint $table) {
            $table->decimal('product_cost', 10, 2)->default(0)->after('subtotal');
        });

        // See docs/SUPABASE.md: every table is locked away from the Data API.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE product_service ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::table('walk_in_visit_items', function (Blueprint $table) {
            $table->dropColumn('product_cost');
        });
        Schema::table('appointment_items', function (Blueprint $table) {
            $table->dropColumn('product_cost');
        });
        Schema::dropIfExists('product_service');
        Schema::dropIfExists('products');
    }
};
