<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walk_in_visits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('guest_name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('staff_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('visited_at')->index();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'visited_at']);
        });

        Schema::create('walk_in_visit_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('walk_in_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_name');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_in_visit_items');
        Schema::dropIfExists('walk_in_visits');
    }
};
