<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MBKBS_LLM
{
    public function __construct(
        private string $base_url,
        private string $api_key,
        private string $model,
    ) {
        $this->base_url = untrailingslashit($base_url);
    }

    public static function from_settings(): ?self
    {
        $enabled = MBKBS_Database::get_setting('llm.enabled', '1');
        if ($enabled === '0' || $enabled === 'false') {
            return null;
        }
        $base = trim(MBKBS_Database::get_setting('llm.base_url', ''));
        $model = trim(MBKBS_Database::get_setting('llm.model', ''));
        $key = trim(MBKBS_Database::get_setting('llm.api_key', ''));
        if ($base === '' || $model === '') {
            return null;
        }
        $is_local = str_contains($base, '127.0.0.1') || str_contains($base, 'localhost');
        if ($key === '' && !$is_local) {
            return null;
        }
        return new self($base, $key !== '' ? $key : 'not-needed', $model);
    }

    public function chat(string $system, string $user, float $temperature = 0.2): string
    {
        $url = $this->base_url . '/chat/completions';
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temperature,
        ];
        $response = wp_remote_post(
            $url,
            [
                'timeout' => 120,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->api_key,
                ],
                'body' => wp_json_encode($payload),
            ]
        );
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid LLM response (HTTP ' . $code . ')');
        }
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            $msg = $data['error']['message'] ?? $raw;
            throw new RuntimeException('LLM error: ' . (is_string($msg) ? $msg : wp_json_encode($msg)));
        }
        return $content;
    }
}
