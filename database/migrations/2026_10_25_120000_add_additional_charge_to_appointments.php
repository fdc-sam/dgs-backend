<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->decimal('additional_charge', 10, 2)->default(0)->after('discount_amount');
            $table->string('additional_charge_reason', 160)->nullable()->after('additional_charge');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['additional_charge', 'additional_charge_reason']);
        });
    }
};
