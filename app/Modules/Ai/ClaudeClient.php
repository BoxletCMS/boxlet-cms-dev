<?php

namespace App\Modules\Ai;

/**
 * ONE QUESTION TO CLAUDE (PLAN.md D-248): Anthropic's Messages API over plain HTTPS, with curl
 * as ResendTransport talks to Resend, so no SDK joins the closed list of what Boxlet runs on.
 *
 * Returns the answer's text and what it cost in tokens, or throws AiFailure with a sentence the
 * owner can act on: the key refused, the model unknown, the account out of credit, the service
 * busy, no answer at all. The provider's own words are kept beside it for the log.
 */
final class ClaudeClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    /**
     * @return array{text: string, in: int, out: int}
     */
    public static function ask(string $key, string $model, string $system, string $user, int $maxTokens = 4096): array
    {
        if (!function_exists('curl_init')) {
            throw new AiFailure(t('ai.error.no_curl'));
        }
        $handle = curl_init(self::ENDPOINT);
        if ($handle === false) {
            throw new AiFailure(t('ai.error.no_answer', ['reason' => 'curl']));
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: ' . self::VERSION,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $status === 0) {
            throw new AiFailure(t('ai.error.no_answer', ['reason' => $error !== '' ? $error : 'no response']));
        }
        $answer = json_decode($body, true);
        if ($status !== 200) {
            $said = is_array($answer) && is_string($answer['error']['message'] ?? null) ? $answer['error']['message'] : 'HTTP ' . $status;
            throw new AiFailure(match (true) {
                $status === 401, $status === 403 => t('ai.error.key'),
                $status === 404 => t('ai.error.model', ['model' => $model]),
                $status === 429 => t('ai.error.busy'),
                $status === 529, $status >= 500 => t('ai.error.overloaded'),
                str_contains(strtolower($said), 'credit') => t('ai.error.credit'),
                default => t('ai.error.refused', ['reason' => $said]),
            }, $said);
        }
        $text = '';
        foreach (is_array($answer['content'] ?? null) ? $answer['content'] : [] as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
        if ($text === '') {
            throw new AiFailure(t('ai.error.empty'));
        }

        return [
            'text' => $text,
            'in' => (int) ($answer['usage']['input_tokens'] ?? 0),
            'out' => (int) ($answer['usage']['output_tokens'] ?? 0),
        ];
    }
}
