<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Deposit summary; the individual receipts live in `payments`. See docs/PAYMENTS.md.
            $table->string('payment_status', 24)->default('not_required')->after('source')->index();
            $table->decimal('deposit_amount', 10, 2)->default(0)->after('total_amount');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('deposit_amount');
            // While a deposit is outstanding the slot is held until this moment, then released by the scheduler.
            $table->timestamp('payment_due_at')->nullable()->after('amount_paid')->index();
            $table->timestamp('payment_reminded_at')->nullable()->after('payment_due_at');
            $table->timestamp('paid_at')->nullable()->after('payment_reminded_at');
        });

        // One row per receipt the customer uploads (or payment staff record by hand).
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 16)->default('gcash');
            // Deposit asked for when the receipt was sent, and what staff confirmed actually arrived.
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_received', 10, 2)->nullable();
            $table->string('reference_number', 40)->index();
            // Private disk path; null when staff recorded the payment without a screenshot.
            $table->string('proof_path')->nullable();
            // submitted | approved | rejected
            $table->string('status', 16)->default('submitted')->index();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropIndex(['payment_due_at']);
            $table->dropColumn(['payment_status', 'deposit_amount', 'amount_paid', 'payment_due_at', 'payment_reminded_at', 'paid_at']);
        });
    }
};
