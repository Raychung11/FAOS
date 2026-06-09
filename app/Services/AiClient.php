<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Provider-agnostic AI client (Claude / OpenAI compatible).
 * Used for natural-language insight on top of the numeric forecast.
 * Returns null when AI_PROVIDER=none so the system degrades gracefully.
 */
final class AiClient
{
    public static function enabled(): bool
    {
        return Config::get('AI_PROVIDER', 'none') !== 'none'
            && Config::get('AI_API_KEY', '') !== '';
    }

    public static function insight(string $prompt): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $provider = Config::get('AI_PROVIDER');
        try {
            return $provider === 'openai' ? self::openai($prompt) : self::claude($prompt);
        } catch (\Throwable $e) {
            Logger::error('AI request failed', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    private static function claude(string $prompt): ?string
    {
        $base = Config::get('AI_BASE_URL') ?: 'https://api.anthropic.com';
        $resp = self::http("$base/v1/messages", [
            'x-api-key: ' . Config::get('AI_API_KEY'),
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ], [
            'model'      => Config::get('AI_MODEL', 'claude-opus-4-7'),
            'max_tokens' => 700,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);
        return $resp['content'][0]['text'] ?? null;
    }

    private static function openai(string $prompt): ?string
    {
        $base = Config::get('AI_BASE_URL') ?: 'https://api.openai.com';
        $resp = self::http("$base/v1/chat/completions", [
            'Authorization: Bearer ' . Config::get('AI_API_KEY'),
            'content-type: application/json',
        ], [
            'model'    => Config::get('AI_MODEL', 'gpt-4o-mini'),
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        return $resp['choices'][0]['message']['content'] ?? null;
    }

    private static function http(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 30,
        ]);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new \RuntimeException('AI HTTP error: ' . $err);
        }
        return json_decode((string) $out, true) ?: [];
    }
}
