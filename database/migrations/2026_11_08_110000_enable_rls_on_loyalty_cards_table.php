<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Match the Supabase Data API lockdown applied to the other public tables. Laravel connects as postgres.
        if (DB::getDriverName() === 'pgsql' && DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'auth') AS enabled")->enabled) {
            DB::statement('ALTER TABLE public.loyalty_cards ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'auth') AS enabled")->enabled) {
            DB::statement('ALTER TABLE public.loyalty_cards DISABLE ROW LEVEL SECURITY');
        }
    }
};
