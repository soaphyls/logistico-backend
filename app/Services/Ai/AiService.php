<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected $apiKey;
    protected $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';
    protected $model = 'llama3-8b-8192'; // Default fast and free model

    public function __construct()
    {
        // Try to get from database first (stored via dashboard)
        $dbConfig = \App\Models\BotConfiguration::where('platform', 'groq')->first();
        
        if ($dbConfig && $dbConfig->is_active) {
            $this->apiKey = $dbConfig->api_key;
            $this->model = $dbConfig->settings['model'] ?? config('services.groq.model', 'llama-3.1-8b-instant');
        } else {
            // Fallback to config/env
            $this->apiKey = config('services.groq.api_key');
            $this->model = config('services.groq.model', 'llama-3.1-8b-instant');
        }
    }

    /**
     * Get a response from the AI for a given message.
     */
    public function getResponse(string $message, array $context = [])
    {
        if (empty($this->apiKey)) {
            return "I'm sorry, my AI support module is currently not configured. Please contact the administrator.";
        }

        try {
            $systemPrompt = "You are the Logistico AI Support Assistant. Logistico is a logistics and supply chain management company. \n" .
                "Be professional, helpful, and concise. Only answer questions related to logistics, tracking, or company operations.\n" .
                "If you don't know something about a specific shipment, advise the user to use the 'track' command.";

            // Add context if provided (e.g., shipment data)
            if (!empty($context)) {
                $systemPrompt .= "\n\nCURRENT CONTEXT DATA:\n" . json_encode($context);
            }

            $response = Http::withToken($this->apiKey)
                ->timeout(10)
                ->post($this->baseUrl, [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $message],
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 500,
                ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content');
            }

            Log::error('Groq AI Error: ' . $response->body());
            return "I'm sorry, I'm having trouble processing your request right now.";

        } catch (\Exception $e) {
            Log::error('AI Service Exception: ' . $e->getMessage());
            return "I'm sorry, something went wrong while talking to my AI brain.";
        }
    }
}
