<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The on/off switch on a service's Loyalty card prices (Services → edit). Off: card holders pay the regular price and
 * the service is left out of the app's card perks; the prices stay saved for when it is turned back on. New services
 * start off. Services that already give card holders something are switched on, so nobody's price changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('loyalty_enabled')->default(false);
        });

        DB::table('services')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('loyalty_pricing', 'less')->where('loyalty_discount', '>', 0))
                ->orWhere(fn ($q) => $q->where('loyalty_pricing', 'tiered')->where(fn ($q) => $q->where('loyalty_price', '>', 0)->orWhere('return_price', '>', 0))))
            ->update(['loyalty_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('loyalty_enabled');
        });
    }
};
