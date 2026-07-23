<?php
declare(strict_types=1);

namespace Bkbs;

final class LlmClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $model,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromSettings(Database $db): ?self
    {
        $enabled = $db->getSetting('llm.enabled', '1');
        if ($enabled === '0' || $enabled === 'false') {
            return null;
        }
        $base = trim((string) $db->getSetting('llm.base_url', ''));
        $model = trim((string) $db->getSetting('llm.model', ''));
        $key = trim((string) $db->getSetting('llm.api_key', ''));
        if ($base === '' || $model === '') {
            return null;
        }
        $isLocal = str_contains($base, '127.0.0.1') || str_contains($base, 'localhost');
        if ($key === '' && !$isLocal) {
            return null;
        }
        return new self($base, $key !== '' ? $key : 'not-needed', $model);
    }

    public function chat(string $system, string $user, float $temperature = 0.2): string
    {
        $url = $this->baseUrl . '/chat/completions';
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temperature,
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 120,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('LLM request failed: ' . ($err ?: "HTTP $code"));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid LLM JSON response (HTTP ' . $code . ')');
        }
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            $msg = $data['error']['message'] ?? $raw;
            throw new \RuntimeException('LLM error: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        return $content;
    }
}
