<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Referral steps are now New → Accepted → In progress → Completed (the reward is paid when completing).
 * Old values: contacted = accepted; completed meant "service done, reward not sent yet", so it continues as
 * in progress; rewarded = completed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Order matters: free up "completed" before reusing it.
        DB::table('referrals')->where('status', 'completed')->update(['status' => 'in_progress']);
        DB::table('referrals')->where('status', 'rewarded')->update(['status' => 'completed']);
        DB::table('referrals')->where('status', 'contacted')->update(['status' => 'accepted']);
    }

    public function down(): void
    {
        DB::table('referrals')->where('status', 'completed')->update(['status' => 'rewarded']);
        DB::table('referrals')->where('status', 'in_progress')->update(['status' => 'completed']);
        DB::table('referrals')->where('status', 'accepted')->update(['status' => 'contacted']);
    }
};
