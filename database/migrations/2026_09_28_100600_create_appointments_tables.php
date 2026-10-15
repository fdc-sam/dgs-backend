<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('appointments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('appointment_number', 32)->unique();
            $table->foreignUlid('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('staff_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->date('appointment_date');
            $table->time('start_time');
            $table->time('end_time');
            // Clean-up time after end_time during which the staff member stays blocked.
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            // appointment_date + start_time, denormalised for reminder and sorting queries.
            $table->dateTime('starts_at')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->text('customer_notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['staff_profile_id', 'appointment_date', 'status']);
            $table->index(['branch_id', 'appointment_date']);
            $table->index(['customer_id', 'starts_at']);
        });

        Schema::create('appointment_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('service_variant_id')->nullable()->constrained()->nullOnDelete();
            // Snapshots so history stays correct after catalog edits.
            $table->string('service_name');
            $table->string('variant_name')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->unsignedSmallInteger('duration_minutes');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });

        Schema::create('appointment_reminders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('appointment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('offset_minutes');
            $table->timestamp('sent_at');
            $table->unique(['appointment_id', 'offset_minutes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reminders');
        Schema::dropIfExists('appointment_items');
        Schema::dropIfExists('appointments');
    }
};
