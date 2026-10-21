<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test accounts: users flagged is_test only ever see test data, and live users never see it (App\Support\TestMode).
 * Customer-made records copy their customer's flag; walk-ins take the flag of the admin who recorded them.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['users', 'appointments', 'walk_in_visits', 'reviews'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('is_test')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['is_test']);
                $table->dropColumn('is_test');
            });
        }
    }
};
