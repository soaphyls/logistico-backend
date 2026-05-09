<?php

namespace App\Services\Bot;

interface BotProviderInterface
{
    /**
     * Set the API key for the provider.
     */
    public function setConfig(array $config): self;

    /**
     * Send a text message to a user.
     */
    public function sendMessage(string $to, string $text, array $options = []): bool;

    /**
     * Send a template or structured message (buttons, etc).
     */
    public function sendTemplate(string $to, string $templateName, array $data = []): bool;

    /**
     * Parse incoming webhook data into a standard format.
     */
    public function parseWebhook(array $data): array;
}
