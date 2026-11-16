<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer earnings move into the app (App\Services\EarningsService). Referral rewards and card endorsement rewards are
 * added to the customer's balance instead of being sent to GCash / paid in cash right away; the customer asks for a
 * cash-out and the admin sends it to their GCash, entering the reference number.
 *
 * Rewards settled the old way (sent to GCash, cash from the friend) are marked paid_directly, so they are not counted
 * again in the balance. A loyalty card through a friend is now paid to the salon by GCash or bank (payment_method),
 * with only the reference number: no receipt screenshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_out_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('gcash_number', 20);
            $table->string('gcash_name', 120);
            $table->string('status', 20)->default('pending')->index();
            $table->string('reference_number', 40)->nullable();
            $table->string('admin_note', 500)->nullable();
            $table->foreignUlid('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->boolean('is_test')->default(false)->index();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->boolean('reward_paid_directly')->default(false);
        });
        Schema::table('loyalty_card_requests', function (Blueprint $table) {
            $table->string('payment_method', 10)->default('gcash');
            $table->boolean('endorser_paid_directly')->default(false);
        });

        DB::table('referrals')->where('status', 'completed')->update(['reward_paid_directly' => true]);
        DB::table('loyalty_card_requests')->update(['endorser_paid_directly' => true]);

        // See docs/SUPABASE.md: every table is locked away from the Data API.
        if (DB::getDriverName() === 'pgsql' && DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'auth') AS enabled")->enabled) {
            DB::statement('ALTER TABLE public.cash_out_requests ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_out_requests');
        Schema::table('referrals', fn (Blueprint $table) => $table->dropColumn('reward_paid_directly'));
        Schema::table('loyalty_card_requests', fn (Blueprint $table) => $table->dropColumn(['payment_method', 'endorser_paid_directly']));
    }
};
