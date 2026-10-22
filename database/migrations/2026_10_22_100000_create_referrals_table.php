<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer referrals: a customer suggests someone (name, mobile number, services they want). Once that person
 * completes a service, the salon pays the referrer a reward through GCash, to the referrer's own mobile number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 20)->index();
            $table->string('notes', 500)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('admin_note', 500)->nullable();
            $table->decimal('reward_amount', 10, 2)->nullable();
            $table->string('reward_reference', 100)->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->boolean('is_test')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('referral_service', function (Blueprint $table) {
            $table->foreignUlid('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['referral_id', 'service_id']);
        });

        // See docs/SUPABASE.md: every table is locked away from the Data API.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE referrals ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE referral_service ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_service');
        Schema::dropIfExists('referrals');
    }
};
