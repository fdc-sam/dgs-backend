<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('services', function (Blueprint $table) { $table->decimal('loyalty_discount', 10, 2)->default(0); $table->decimal('first_visit_discount', 10, 2)->default(0); $table->boolean('first_visit_discount_enabled')->default(false); }); }
    public function down(): void { Schema::table('services', function (Blueprint $table) { $table->dropColumn(['loyalty_discount', 'first_visit_discount', 'first_visit_discount_enabled']); }); }
};
