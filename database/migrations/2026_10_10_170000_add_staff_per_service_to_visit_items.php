<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_in_visit_items', function (Blueprint $table) {
            $table->foreignUlid('staff_profile_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
        });

        Schema::table('appointment_items', function (Blueprint $table) {
            $table->foreignUlid('staff_profile_id')->nullable()->after('service_variant_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_profile_id');
        });

        Schema::table('walk_in_visit_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_profile_id');
        });
    }
};
