<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The number on the loyalty card a customer bought at the salon. One card, one customer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('loyalty_number', 32)->nullable()->unique()->after('qr_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['loyalty_number']);
            $table->dropColumn('loyalty_number');
        });
    }
};
