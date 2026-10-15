<?php

use App\Support\CustomerCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 28 chars today (DGS-XXXX-XXXX-XXXX-XXXX-XXXX); room to grow. Unique, so no two customers can share a code.
            $table->string('qr_code', 40)->nullable()->unique()->after('profile_photo');
        });

        // Give every existing customer a code (new customers get theirs when they are created, see User::booted()).
        DB::table('users')->where('role', 'customer')->whereNull('qr_code')->orderBy('id')->select('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                do {
                    $code = CustomerCode::generate();
                } while (DB::table('users')->where('qr_code', $code)->exists());

                DB::table('users')->where('id', $row->id)->update(['qr_code' => $code]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['qr_code']);
            $table->dropColumn('qr_code');
        });
    }
};
