<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stage 4b: current knowledge-index stub. Honest A2A card is Stage 4c/9.
 * Do not change bytes in this slice.
 */
final class MBKBS_Export_Agent
{
    /**
     * @return array<string, mixed>
     */
    public static function build(string $name, string $home): array
    {
        return [
            'name' => $name,
            'url' => $home,
            'protocol' => 'agent-web-protocol-stub',
            'platform' => 'wordpress',
            'knowledge' => [
                'llms_txt' => $home . '/llms.txt',
                'graph' => $home . '/graph.json',
            ],
        ];
    }
}
