<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BotConfiguration;
use App\Models\User;
use App\Services\Bot\BotEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BotController extends Controller
{
    protected $botEngine;

    public function __construct(BotEngine $botEngine)
    {
        $this->botEngine = $botEngine;
    }

    /**
     * Handle incoming webhooks from Telegram/WhatsApp.
     */
    public function handle(Request $request, string $platform)
    {
        try {
            Log::info("Bot Webhook received for {$platform}", $request->all());
            
            $this->botEngine->handleWebhook($platform, $request->all());

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error("Bot Webhook Error ({$platform}): " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get current bot configurations.
     */
    public function index()
    {
        $configs = BotConfiguration::all();
        return response()->json(['data' => $configs]);
    }

    /**
     * Update or create bot configuration.
     */
    public function update(Request $request)
    {
        $request->validate([
            'platform' => 'required|in:telegram,whatsapp,groq',
            'api_key' => 'required|string',
            'api_secret' => 'nullable|string',
            'is_active' => 'required|boolean',
            'settings' => 'nullable|array',
        ]);

        $config = BotConfiguration::updateOrCreate(
            ['platform' => $request->platform],
            [
                'api_key' => $request->api_key,
                'api_secret' => $request->api_secret,
                'is_active' => $request->is_active,
                'settings' => $request->settings,
                'webhook_url' => in_array($request->platform, ['telegram', 'whatsapp']) 
                    ? url("/api/v1/bot/webhook/{$request->platform}") 
                    : null,
            ]
        );

        return response()->json([
            'message' => 'Bot configuration updated successfully.',
            'data' => $config
        ]);
    }

    /**
     * Automatically sync the webhook URL with the platform API.
     */
    public function syncWebhook(Request $request, string $platform)
    {
        try {
            $provider = $this->botEngine->getProvider($platform);
            
            // In a real scenario, this would call the platform's API to set the webhook
            // For Telegram: https://api.telegram.org/bot<token>/setWebhook?url=<url>
            
            if ($platform === 'telegram') {
                $configModel = BotConfiguration::where('platform', 'telegram')->first();
                $baseUrl = rtrim(config('app.url'), '/');
                $webhookUrl = "{$baseUrl}/api/v1/bot/webhook/telegram";
                
                $response = \Illuminate\Support\Facades\Http::get("https://api.telegram.org/bot{$configModel->api_key}/setWebhook", [
                    'url' => $webhookUrl
                ]);

                if (!$response->successful()) {
                    throw new \Exception("Telegram Error: " . $response->body());
                }
            }

            return response()->json([
                'message' => "Webhook successfully synced with " . ucfirst($platform),
                'url' => url("/api/v1/bot/webhook/{$platform}")
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Generate a verification code for a specific user (Admin only).
     */
    public function generateCodeForUser(Request $request, $userId)
    {
        try {
            if (auth()->user()->role_id != 1) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = User::findOrFail($userId);
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            
            $user->update(['bot_verification_code' => $code]);

            return response()->json([
                'message' => 'Code generated for ' . $user->name,
                'code' => $code
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to generate code for user: " . $e->getMessage());
            return response()->json(['message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Generate a verification code for the current user.
     */
    public function generateCode(Request $request)
    {
        $user = $request->user();
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $user->update(['bot_verification_code' => $code]);

        return response()->json([
            'code' => $code,
            'message' => 'Verification code generated. Please send this code to the bot.'
        ]);
    }
}
