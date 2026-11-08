<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number', 32)->unique(); // digits only; formatted with the salon's prefix for display
            $table->foreignUlid('customer_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_test')->default(false)->index();
            $table->timestamps();
        });

        // Preserve every card already assigned to a customer as an inventory record.
        DB::table('users')->whereNotNull('loyalty_number')->orderBy('id')->chunk(500, function ($users) {
            foreach ($users as $user) {
                DB::table('loyalty_cards')->insertOrIgnore([
                    'id' => (string) \Illuminate\Support\Str::ulid(),
                    'number' => $user->loyalty_number,
                    'customer_id' => $user->id,
                    'is_test' => (bool) $user->is_test,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_cards');
    }
};
