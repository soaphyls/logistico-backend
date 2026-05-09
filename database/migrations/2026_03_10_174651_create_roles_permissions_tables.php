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
        // Create permissions table if not exists
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->unique();
                $table->string('description')->nullable();
                $table->string('category')->nullable();
                $table->timestamps();
            });
        }

        // Update existing roles to have slug if empty
        if (Schema::hasTable('roles')) {
            $roles = DB::table('roles')->get();
            foreach ($roles as $role) {
                if (empty($role->slug)) {
                    DB::table('roles')
                        ->where('id', $role->id)
                        ->update(['slug' => \Str::slug($role->name)]);
                }
            }
        }

        // Role-Permission pivot table (role defaults)
        if (!Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained()->onDelete('cascade');
                $table->foreignId('permission_id')->constrained()->onDelete('cascade');
                $table->timestamps();
                $table->unique(['role_id', 'permission_id']);
            });
        }

        // User-Role relationship
        if (!Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('role_id')->constrained()->onDelete('cascade');
                $table->timestamps();
                $table->unique(['user_id', 'role_id']);
            });
        }

        // User Permission Overrides (per-user grants/revokes)
        if (!Schema::hasTable('user_permission_overrides')) {
            Schema::create('user_permission_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('permission_id')->constrained()->onDelete('cascade');
                $table->enum('override_type', ['grant', 'revoke']);
                $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
                $table->timestamps();
                $table->unique(['user_id', 'permission_id']);
            });
        }

        // Audit Logs for permission changes
        if (!Schema::hasTable('permission_audit_logs')) {
            Schema::create('permission_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('changed_by')->constrained('users')->onDelete('cascade');
                $table->string('action');
                $table->string('permission')->nullable();
                $table->string('old_value')->nullable();
                $table->string('new_value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_audit_logs');
        Schema::dropIfExists('user_permission_overrides');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
    }
};
