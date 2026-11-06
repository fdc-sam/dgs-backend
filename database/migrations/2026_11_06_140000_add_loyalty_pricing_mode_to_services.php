<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each service picks how loyalty card holders are charged (Services → edit → Loyalty card prices):
 *  - "less": an amount or percent off every option and branch price (loyalty_discount + loyalty_discount_type);
 *  - "tiered": the maintenance price (loyalty_price) and the lapsed price (return_price) after no visit within the period.
 * Services that used the old return discount become tiered (their prices were converted by the previous migration);
 * every other service keeps its old amount off as "less", so nobody's price changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('loyalty_pricing', 10)->default('less');
            $table->string('loyalty_discount_type', 10)->default('amount');
        });

        DB::table('services')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('first_visit_discount_enabled', true)->where('first_visit_discount', '>', 0))
                // Tiered prices set in the form before this existed, with no old amount off.
                ->orWhere(fn ($q) => $q->where('loyalty_discount', '<=', 0)->where(fn ($q) => $q->where('loyalty_price', '>', 0)->orWhere('return_price', '>', 0))))
            ->update(['loyalty_pricing' => 'tiered']);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['loyalty_pricing', 'loyalty_discount_type']);
        });
    }
};
