<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('promotions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            // percentage | fixed | special_price | package | buy_x_get_y
            $table->string('discount_type', 32);
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('promo_price', 10, 2)->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->text('terms_and_conditions')->nullable();
            // draft | active | inactive
            $table->string('status', 32)->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'start_at', 'end_at']);
        });

        Schema::create('promotion_service', function (Blueprint $table) {
            $table->foreignUlid('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['promotion_id', 'service_id']);
        });

        Schema::create('promotion_branch', function (Blueprint $table) {
            $table->foreignUlid('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->primary(['promotion_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_branch');
        Schema::dropIfExists('promotion_service');
        Schema::dropIfExists('promotions');
    }
};
