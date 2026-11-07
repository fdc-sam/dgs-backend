<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_in_visits', function (Blueprint $table) {
            $table->foreignUlid('customer_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->index(['customer_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::table('walk_in_visits', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id', 'visited_at']);
            $table->dropColumn('customer_id');
        });
    }
};
