<?php
/**
 * AdvisorOS — AI assistant service (Module O).
 *
 * Provider-agnostic. When an API key is configured (.env: AI_PROVIDER,
 * AI_API_KEY, AI_MODEL) it calls the provider over HTTPS via cURL — no
 * SDK / Composer needed. With no key it returns a deterministic draft
 * built from the client's own data so the feature is usable offline.
 *
 * Every call is recorded in ai_logs and every response carries the
 * mandatory not-financial-advice disclaimer.
 */

declare(strict_types=1);

/** AI is "live" only when a provider + key are configured and cURL exists. */
function ai_enabled(): bool
{
    return function_exists('curl_init')
        && (string) env('AI_API_KEY', '') !== ''
        && in_array(strtolower((string) env('AI_PROVIDER', '')), ['anthropic', 'openai'], true);
}

function ai_provider(): string
{
    return strtolower((string) env('AI_PROVIDER', 'anthropic'));
}

function ai_model(): string
{
    $default = ai_provider() === 'openai' ? 'gpt-4o-mini' : 'claude-sonnet-4-6';
    return (string) env('AI_MODEL', $default);
}

/** Persist an AI interaction (best effort — never breaks the request). */
function ai_log(string $feature, string $prompt, string $response, string $model, ?int $tokens): void
{
    try {
        $u = current_user();
        db()->prepare(
            'INSERT INTO ai_logs (tenant_id,user_id,feature,prompt,response,model,tokens_used)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $u['tenant_id'] ?? null,
            $u['id'] ?? null,
            substr($feature, 0, 60),
            substr($prompt, 0, 20000),
            substr($response, 0, 20000),
            substr($model, 0, 80),
            $tokens,
        ]);
    } catch (Throwable $e) {
        error_log('[AdvisorOS] ai_log failed: ' . $e->getMessage());
    }
}

/** Guarantee the compliance disclaimer is present exactly once. */
function ai_with_disclaimer(string $text): string
{
    $d = ai_disclaimer();
    if (str_contains($text, $d)) {
        return rtrim($text);
    }
    return rtrim($text) . "\n\n— — —\n" . $d;
}

/**
 * Run a completion.
 *
 * @return array{text:string,model:string,tokens:?int,stubbed:bool,error:?string}
 */
function ai_complete(string $system, string $user, string $feature, string $stub): array
{
    if (!ai_enabled()) {
        $text = ai_with_disclaimer($stub);
        ai_log($feature, $user, $text, 'stub', null);
        return ['text' => $text, 'model' => 'stub', 'tokens' => null,
                'stubbed' => true, 'error' => null];
    }

    try {
        [$raw, $model, $tokens] = ai_provider() === 'openai'
            ? ai_call_openai($system, $user)
            : ai_call_anthropic($system, $user);
        $text = ai_with_disclaimer(trim($raw) !== '' ? $raw : $stub);
        ai_log($feature, $user, $text, $model, $tokens);
        return ['text' => $text, 'model' => $model, 'tokens' => $tokens,
                'stubbed' => false, 'error' => null];
    } catch (Throwable $e) {
        // Graceful degradation: fall back to the data-driven draft.
        error_log('[AdvisorOS] AI call failed: ' . $e->getMessage());
        $text = ai_with_disclaimer($stub);
        ai_log($feature, $user, $text, 'stub(fallback)', null);
        return ['text' => $text, 'model' => 'stub', 'tokens' => null,
                'stubbed' => true, 'error' => 'AI provider unavailable — showing a data-driven draft.'];
    }
}

/**
 * Split a generated proposal draft into its four narrative sections.
 * Tolerant of `#`/`##`/`###`, optional numbering and trailing colons.
 * Anything before the first header (or all of it, if no headers) falls
 * back to the executive summary. The disclaimer block is dropped.
 *
 * @return array{executive_summary:string,current_gaps:string,recommendations:string,action_plan:string}
 */
function ai_split_sections(string $text): array
{
    $text = preg_split('/\n\s*(?:(?:—|–)\s*){3,}\s*\n|\n\s*-{3,}\s*\n/u', $text, 2)[0];
    $map  = [
        'EXECUTIVE SUMMARY' => 'executive_summary',
        'CURRENT GAPS'      => 'current_gaps',
        'RECOMMENDATIONS'   => 'recommendations',
        'ACTION PLAN'       => 'action_plan',
    ];
    $out  = array_fill_keys(array_values($map), '');

    $parts = preg_split(
        '/^[ \t]*#{0,6}[ \t]*\*{0,2}[ \t]*\d*\.?[ \t]*'
        . '(EXECUTIVE SUMMARY|CURRENT GAPS|RECOMMENDATIONS|ACTION PLAN)'
        . '[ \t]*\*{0,2}[ \t]*:?[ \t]*$/im',
        $text, -1, PREG_SPLIT_DELIM_CAPTURE
    );

    if (count($parts) <= 1) {
        $out['executive_summary'] = trim($text);
        return $out;
    }
    if (trim((string) $parts[0]) !== '') {
        $out['executive_summary'] = trim((string) $parts[0]);
    }
    for ($i = 1; $i < count($parts); $i += 2) {
        $key = $map[strtoupper(trim($parts[$i]))] ?? null;
        if ($key !== null) {
            $out[$key] = trim((string) ($parts[$i + 1] ?? ''));
        }
    }
    return $out;
}

/** @return array{0:string,1:string,2:?int} [text, model, tokens] */
function ai_call_anthropic(string $system, string $user): array
{
    $model = ai_model();
    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => 1024,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $user]],
    ]);
    $resp = ai_http('https://api.anthropic.com/v1/messages', $payload, [
        'x-api-key: ' . (string) env('AI_API_KEY', ''),
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ]);
    $data = json_decode($resp, true);
    $text = $data['content'][0]['text'] ?? '';
    $tok  = isset($data['usage'])
        ? (int) (($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0))
        : null;
    if ($text === '') {
        throw new RuntimeException('Empty Anthropic response: ' . substr($resp, 0, 300));
    }
    return [$text, $model, $tok];
}

/** @return array{0:string,1:string,2:?int} [text, model, tokens] */
function ai_call_openai(string $system, string $user): array
{
    $model = ai_model();
    $payload = json_encode([
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'max_tokens' => 1024,
    ]);
    $resp = ai_http('https://api.openai.com/v1/chat/completions', $payload, [
        'Authorization: Bearer ' . (string) env('AI_API_KEY', ''),
        'Content-Type: application/json',
    ]);
    $data = json_decode($resp, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    $tok  = isset($data['usage']['total_tokens']) ? (int) $data['usage']['total_tokens'] : null;
    if ($text === '') {
        throw new RuntimeException('Empty OpenAI response: ' . substr($resp, 0, 300));
    }
    return [$text, $model, $tok];
}

/** Minimal hardened JSON POST. Throws on transport / HTTP error. */
function ai_http(string $url, string $body, array $headers): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('cURL error: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Provider HTTP {$code}: " . substr((string) $resp, 0, 300));
    }
    return (string) $resp;
}
