<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Optional downpayment: a QR the owner generated in GCash for exactly this amount. See docs/PAYMENTS.md.
            $table->decimal('deposit_amount', 10, 2)->nullable()->after('regular_price');
            $table->string('gcash_qr')->nullable()->after('image');
        });

        Schema::table('appointment_items', function (Blueprint $table) {
            // Snapshot of the service's own downpayment at booking time (0 = none / salon-wide rule).
            $table->decimal('deposit_amount', 10, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_items', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['deposit_amount', 'gcash_qr']);
        });
    }
};
