<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('staff_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_name');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
            $table->index(['referral_id', 'service_id']);
        });

        // Preserve services older referrals already selected before the salon chose them.
        DB::table('referral_service')
            ->join('services', 'services.id', '=', 'referral_service.service_id')
            ->select('referral_service.referral_id', 'services.id as service_id', 'services.name', 'services.regular_price')
            ->orderBy('referral_service.referral_id')
            ->cursor()->each(function ($service) {
                DB::table('referral_items')->insert([
                    'id' => (string) Str::ulid(),
                    'referral_id' => $service->referral_id,
                    'service_id' => $service->service_id,
                    'service_name' => $service->name,
                    'quantity' => 1,
                    'unit_price' => $service->regular_price,
                    'subtotal' => $service->regular_price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE referral_items ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_items');
    }
};
