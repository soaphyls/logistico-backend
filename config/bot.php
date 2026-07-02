<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot Webhook Security
    |--------------------------------------------------------------------------
    |
    | Set a shared secret to validate incoming webhooks. The platform should
    | send this value in the X-Bot-Webhook-Secret header with every request.
    |
    | For Telegram: set your bot token as the telegram_secret to validate
    | the X-Telegram-Bot-Api-Secret-Token header.
    |
    */

    'webhook_secret' => env('BOT_WEBHOOK_SECRET'),

    'telegram_secret' => env('BOT_TELEGRAM_SECRET'),

];
