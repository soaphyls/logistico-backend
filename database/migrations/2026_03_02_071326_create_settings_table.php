<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('text'); // text, image, encrypted
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Insert default settings
        DB::table('settings')->insert([
            ['key' => 'app_name', 'value' => 'LOGISTICO', 'type' => 'text', 'description' => 'Application Name'],
            ['key' => 'app_logo', 'value' => null, 'type' => 'image', 'description' => 'Application Logo'],
            ['key' => 'app_favicon', 'value' => null, 'type' => 'image', 'description' => 'Application Favicon'],
            ['key' => 'primary_color', 'value' => '#f97316', 'type' => 'text', 'description' => 'Primary Brand Color'],
            ['key' => 'payment_public_key', 'value' => null, 'type' => 'encrypted', 'description' => 'Payment Gateway Public Key'],
            ['key' => 'payment_secret_key', 'value' => null, 'type' => 'encrypted', 'description' => 'Payment Gateway Secret Key'],
            ['key' => 'payment_webhook_secret', 'value' => null, 'type' => 'encrypted', 'description' => 'Payment Gateway Webhook Secret'],
            ['key' => 'payment_mode', 'value' => 'test', 'type' => 'text', 'description' => 'Payment Mode: test or live'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
