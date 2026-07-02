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
        Log::warning("WhatsApp not implemented: message to {$to} was not sent. Install Twilio/Meta Business API integration.");
        throw new \RuntimeException('WhatsApp provider is not implemented. Messages cannot be sent.');
    }

    public function sendTemplate(string $to, string $templateName, array $data = []): bool
    {
        Log::warning("WhatsApp not implemented: template to {$to} was not sent.");
        throw new \RuntimeException('WhatsApp provider is not implemented. Templates cannot be sent.');
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
