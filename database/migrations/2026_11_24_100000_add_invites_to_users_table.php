<?php

use App\Support\InviteCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite a friend to DGS Membership: every customer gets a short invite code for their link (/i/{code}), and a new
 * customer remembers who invited them (from the link, or a code typed within a week of signing up). Their membership
 * request then goes through that friend, who earns the reward. See App\Services\InviteService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('invite_code', 16)->nullable()->unique()->after('qr_code');
            $table->foreignUlid('invited_by_id')->nullable()->after('invite_code')->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable()->after('invited_by_id');
        });

        DB::table('users')->where('role', 'customer')->whereNull('invite_code')->orderBy('id')->select(['id', 'name'])
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    do {
                        $code = InviteCode::generate((string) $row->name);
                    } while (DB::table('users')->where('invite_code', $code)->exists());
                    DB::table('users')->where('id', $row->id)->update(['invite_code' => $code]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by_id');
            $table->dropUnique(['invite_code']);
            $table->dropColumn(['invite_code', 'invited_at']);
        });
    }
};
