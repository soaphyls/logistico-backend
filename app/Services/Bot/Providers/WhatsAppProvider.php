<?php

namespace App\Services\Bot\Providers;

use App\Services\Bot\BotProviderInterface;
use Illuminate\Support\Facades\Log;

class WhatsAppProvider implements BotProviderInterface
{
    protected $config;

    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function sendMessage(string $to, string $text, array $options = []): bool
    {
        Log::info("WhatsApp Outgoing to {$to}: {$text}");
        // Implementation for Twilio/Meta Business API goes here
        return true;
    }

    public function sendTemplate(string $to, string $templateName, array $data = []): bool
    {
        Log::info("WhatsApp Template to {$to}: {$templateName}");
        return true;
    }

    public function parseWebhook(array $data): array
    {
        // Standardize different WhatsApp providers (Twilio, Meta, etc)
        return [
            'from' => $data['from'] ?? '',
            'text' => $data['body'] ?? '',
            'raw' => $data
        ];
    }
}
