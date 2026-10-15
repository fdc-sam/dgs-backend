<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // When the customer showed up at the branch and who recorded it (admin app: QR scan or manual check-in).
            $table->timestamp('checked_in_at')->nullable()->after('confirmed_at');
            $table->foreignUlid('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
            // How the booking came about: 'app' (customer booked) or 'walk_in' (created at the counter by staff).
            $table->string('source', 16)->default('app')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn(['checked_in_at', 'source']);
        });
    }
};
