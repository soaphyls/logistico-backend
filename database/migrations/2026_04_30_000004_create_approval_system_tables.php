<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_chains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('module'); // transfers, adjustments, inbound, etc
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approval_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_chain_id')->constrained('approval_chains')->onDelete('cascade');
            $table->integer('level_order');
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['approval_chain_id', 'level_order']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_chain_id')->constrained('approval_chains')->onDelete('cascade');
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');
            $table->integer('current_level_order')->default(1);
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id'], 'approval_req_morph_index');
            $table->index('status');
        });

        Schema::create('approval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('level_order');
            $table->string('action'); // approved, rejected
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_logs');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_levels');
        Schema::dropIfExists('approval_chains');
    }
};