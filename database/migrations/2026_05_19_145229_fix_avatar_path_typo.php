<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix the typo in avatar paths: 'avartars' -> 'avatars'
     */
    public function up(): void
    {
        DB::table('users')
            ->where('avatar', 'like', '%uploads/avartars/%')
            ->update([
                'avatar' => DB::raw("REPLACE(avatar, 'uploads/avartars/', 'uploads/avatars/')")
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('avatar', 'like', '%uploads/avatars/%')
            ->update([
                'avatar' => DB::raw("REPLACE(avatar, 'uploads/avatars/', 'uploads/avartars/')")
            ]);
    }
};
