<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer asking for a loyalty card through a friend (the endorser) who has one: they scanned the friend's QR,
 * paid the salon's share by GCash and uploaded the receipt. The admin checks the payment and approves (the card number
 * set aside here is linked to the customer) or rejects it. See App\Services\LoyaltyCardRequestService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_card_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('endorser_id')->constrained('users')->cascadeOnDelete();
            // The card number set aside for this request while it waits; linked to the customer on approval.
            $table->foreignUlid('loyalty_card_id')->nullable()->constrained('loyalty_cards')->nullOnDelete();
            $table->decimal('gcash_amount', 10, 2);
            $table->decimal('endorser_cash', 10, 2);
            $table->string('reference_number', 40)->index();
            $table->string('proof_path')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('is_test')->default(false)->index();
            $table->timestamps();

            $table->index(['endorser_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_card_requests');
    }
};
