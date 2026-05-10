<?php

namespace App\Services\Bot\Providers;

use App\Services\Bot\BotProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramProvider implements BotProviderInterface
{
    protected $apiKey;
    protected $baseUrl = 'https://api.telegram.org/bot';

    public function setConfig(array $config): self
    {
        $this->apiKey = $config['api_key'];
        return $this;
    }

    public function sendMessage(string $to, string $text, array $options = []): bool
    {
        try {
            $response = Http::timeout(5)->post("{$this->baseUrl}{$this->apiKey}/sendMessage", [
                'chat_id' => $to,
                'text' => $text,
                'parse_mode' => 'HTML'
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Telegram Send Error: " . $e->getMessage());
            return false;
        }
    }

    public function sendTemplate(string $to, string $templateName, array $data = []): bool
    {
        // Telegram doesn't use "templates" like WhatsApp, but we can implement preset layouts here
        return $this->sendMessage($to, $templateName);
    }

    public function parseWebhook(array $data): array
    {
        if (!isset($data['message'])) return [];

        return [
            'from' => $data['message']['chat']['id'],
            'text' => $data['message']['text'] ?? '',
            'raw' => $data
        ];
    }
}
