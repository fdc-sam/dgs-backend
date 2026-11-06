<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty card discounts become the prices card holders pay (Services → Loyalty card prices):
 *  - loyalty_price: what a customer with an active card pays;
 *  - return_price: what they pay instead when they have not had the service within the return period.
 * Each is converted from the old amount off the regular price, so nobody's price changes. The old
 * loyalty_discount / first_visit_discount columns are left in place, unused, so this can be rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('loyalty_price', 10, 2)->nullable();
            $table->decimal('return_price', 10, 2)->nullable();
        });

        DB::table('services')->orderBy('id')->each(function ($service) {
            $regular = (float) $service->regular_price;
            // ₱1,500 regular with ₱1,000 off → a ₱500 price; no discount, or one that made it free, → no special price.
            $price = fn ($discount) => (float) $discount > 0 && $regular - (float) $discount > 0 ? round($regular - (float) $discount, 2) : null;

            DB::table('services')->where('id', $service->id)->update([
                'loyalty_price' => $price($service->loyalty_discount),
                'return_price' => $price($service->first_visit_discount),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['loyalty_price', 'return_price']);
        });
    }
};
