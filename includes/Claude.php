<?php

class Claude
{
    private string $apiKey;
    private string $model = 'claude-haiku-4-5-20251001';
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function send(string $systemPrompt, array $messages, float $temperature = 0.7, int $maxTokens = 2048): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'system' => $systemPrompt,
            'messages' => $messages
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => 'cURL error: ' . $error];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = $data['error']['message'] ?? 'Unknown API error (HTTP ' . $httpCode . ')';
            return ['error' => $msg];
        }

        return [
            'content' => $data['content'][0]['text'] ?? '',
            'usage' => $data['usage'] ?? [],
            'stop_reason' => $data['stop_reason'] ?? null
        ];
    }

    public function sendJson(string $systemPrompt, string $userMessage, float $temperature = 0.7, int $maxTokens = 4096): array
    {
        // Use assistant prefill to force JSON output
        $messages = [
            ['role' => 'user', 'content' => $userMessage],
            ['role' => 'assistant', 'content' => '{']
        ];

        $result = $this->send(
            $systemPrompt . "\n\nIMPORTANT: Respond with valid JSON only. No markdown, no code fences, no explanation outside the JSON.",
            $messages,
            $temperature,
            $maxTokens
        );

        if (isset($result['error'])) {
            return $result;
        }

        // Detect truncated responses
        if (($result['stop_reason'] ?? null) === 'max_tokens') {
            return ['error' => 'Response truncated (max_tokens reached). The AI generated more content than the token limit allows.', 'raw' => '{' . $result['content']];
        }

        // Prepend the prefilled '{' back onto the response
        $text = trim('{' . $result['content']);

        $parsed = $this->extractJson($text);
        if ($parsed === null) {
            return ['error' => 'Failed to parse JSON response: ' . json_last_error_msg(), 'raw' => $text];
        }

        return ['data' => $parsed, 'usage' => $result['usage']];
    }

    private function extractJson(string $text): ?array
    {
        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/s', '', $text);
        $text = preg_replace('/\s*```\s*$/s', '', $text);
        $text = trim($text);

        // Replace smart/curly quotes with straight quotes or escaped versions
        // These break JSON when AI uses them inside string values (e.g. nicknames)
        $text = str_replace(
            ["\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x93", "\xe2\x80\x94"],
            ['\\"', '\\"', "'", "'", "-", "-"],
            $text
        );

        // Sanitize control characters inside JSON strings
        $sanitized = preg_replace_callback('/"((?:[^"\\\\]|\\\\.)*)"/s', function($m) {
            $inner = $m[1];
            $inner = str_replace(["\r\n", "\r", "\n", "\t"], ["\\n", "\\n", "\\n", "\\t"], $inner);
            return '"' . $inner . '"';
        }, $text);

        // Try parsing the full text first
        $parsed = json_decode($sanitized, true);
        if ($parsed !== null) return $parsed;

        // Try to find a JSON object in the text (AI may have added text before/after)
        if (preg_match('/\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\}/s', $text, $match)) {
            $candidate = preg_replace_callback('/"((?:[^"\\\\]|\\\\.)*)"/s', function($m) {
                $inner = $m[1];
                $inner = str_replace(["\r\n", "\r", "\n", "\t"], ["\\n", "\\n", "\\n", "\\t"], $inner);
                return '"' . $inner . '"';
            }, $match[0]);
            $parsed = json_decode($candidate, true);
            if ($parsed !== null) return $parsed;
        }

        return null;
    }
}
