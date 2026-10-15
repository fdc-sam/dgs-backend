<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_in_visits', function (Blueprint $table) {
            $table->string('status', 20)->default('in_progress')->after('visited_at')->index();
            $table->timestamp('completed_at')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('walk_in_visits', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['completed_at']);
            $table->dropColumn(['status', 'completed_at']);
        });
    }
};
