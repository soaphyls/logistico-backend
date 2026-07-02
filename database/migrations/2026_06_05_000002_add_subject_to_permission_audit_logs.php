<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permission_audit_logs')) {
            return;
        }

        Schema::table('permission_audit_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('permission_audit_logs', 'subject_type')) {
                $table->string('subject_type')->nullable()->after('action');
            }
            if (!Schema::hasColumn('permission_audit_logs', 'subject_id')) {
                $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            }
            if (!Schema::hasColumn('permission_audit_logs', 'meta')) {
                $table->json('meta')->nullable()->after('new_value');
            }
        });

        Schema::table('permission_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('permission_audit_logs')) {
            return;
        }

        Schema::table('permission_audit_logs', function (Blueprint $table) {
            if (Schema::hasColumn('permission_audit_logs', 'meta')) {
                $table->dropColumn('meta');
            }
            if (Schema::hasColumn('permission_audit_logs', 'subject_id')) {
                $table->dropColumn('subject_id');
            }
            if (Schema::hasColumn('permission_audit_logs', 'subject_type')) {
                $table->dropColumn('subject_type');
            }
        });
    }
};
