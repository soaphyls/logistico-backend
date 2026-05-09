<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('platform_user_id'); // Telegram Chat ID or WhatsApp Number
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('platform'); // telegram, whatsapp
            $table->string('last_intent')->nullable();
            $table->json('context_data')->nullable();
            $table->timestamps();
            
            $table->index(['platform', 'platform_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_sessions');
    }
};
