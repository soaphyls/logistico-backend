<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles') && !Schema::hasColumn('roles', 'slug')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('slug')->unique()->nullable()->after('name');
            });

            // Populate slug from existing roles
            $roles = DB::table('roles')->get();
            foreach ($roles as $role) {
                DB::table('roles')
                    ->where('id', $role->id)
                    ->update(['slug' => Str::slug($role->name)]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'slug')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('slug');
            });
        }
    }
};
