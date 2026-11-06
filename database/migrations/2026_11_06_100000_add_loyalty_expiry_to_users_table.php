<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A loyalty card is good for a year (config salon.loyalty.validity_months) from the day the salon links or renews it.
 * Cards linked before this existed get their year from the day they were linked (the audit log), or from today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('loyalty_expires_at')->nullable()->after('loyalty_number');
        });

        $months = (int) config('salon.loyalty.validity_months', 12);
        DB::table('users')->whereNotNull('loyalty_number')->orderBy('id')->each(function ($user) use ($months) {
            $linked = DB::table('audit_logs')
                ->where('action', 'customer.loyalty_number')->where('entity_id', $user->id)
                ->orderByDesc('created_at')->get(['new_values', 'created_at'])
                ->first(fn ($log) => (json_decode((string) $log->new_values, true)['loyalty_number'] ?? null) === $user->loyalty_number);
            $from = $linked ? Carbon::parse($linked->created_at) : Carbon::today();

            DB::table('users')->where('id', $user->id)
                ->update(['loyalty_expires_at' => $from->startOfDay()->addMonthsNoOverflow($months)->toDateString()]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('loyalty_expires_at');
        });
    }
};
