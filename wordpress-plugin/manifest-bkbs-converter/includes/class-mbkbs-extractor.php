<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MBKBS_Extractor
{
    private const SYSTEM = <<<'PROMPT'
You are a Business Knowledge Base (BKBS) extractor.
Return ONLY valid JSON: {"entities":[{"entity_type":"...","name":"...","description":"...","properties":{},"relationships":[],"evidence":[{"url":"...","snippet":"...","kind":"text"}],"trust_level":"medium"}]}
entity_type must be one of: business_identity, product_service, capability, expertise, facility_served, operational_problem, project, knowledge_article, policy, team, asset, relationship
Do not invent facts not in the text.
PROMPT;

    /**
     * @param list<array{url:string,title:?string,text:string}> $pages
     * @return list<array<string,mixed>>
     */
    public function extract_heuristic(array $pages): array
    {
        $entities = [];
        $keywords = [
            'cctv' => 'CCTV Installation',
            'access control' => 'Access Control Systems',
            'alarm' => 'Alarm Systems',
            'intrusion' => 'Intrusion Detection',
            'security' => 'Security Services',
            'maintenance' => 'Maintenance Services',
            'consulting' => 'Consulting',
        ];
        $facilities = ['warehouse', 'office', 'retail', 'hospital', 'school', 'industrial', 'commercial'];

        foreach ($pages as $i => $page) {
            $text = strtolower($page['text'] ?? '');
            $url = $page['url'];
            $title = $page['title'] ?: $url;

            if ($i === 0) {
                $entities[] = $this->ent('business_identity', $title, substr($page['text'], 0, 500), $url, 'heuristic');
            }
            foreach ($keywords as $kw => $label) {
                if (str_contains($text, $kw)) {
                    $entities[] = $this->ent('capability', $label, "Mentioned on: {$title}", $url, 'heuristic');
                }
            }
            foreach ($facilities as $f) {
                if (preg_match('/\b' . preg_quote($f, '/') . '\b/', $text)) {
                    $entities[] = $this->ent('facility_served', ucfirst($f) . (str_ends_with($f, 's') ? '' : 's'), "Referenced on {$title}", $url, 'heuristic');
                }
            }
            if (str_contains($url, 'privacy') || str_contains($text, 'privacy policy')) {
                $entities[] = $this->ent('policy', 'Privacy Policy', substr($page['text'], 0, 400), $url, 'heuristic');
            }
        }
        return $entities;
    }

    /**
     * @param list<array{url:string,title:?string,text:string}> $pages
     * @return list<array<string,mixed>>
     */
    public function extract_with_llm(MBKBS_LLM $llm, array $pages, string $base_url): array
    {
        $batch = array_slice($pages, 0, 8);
        $digests = [];
        foreach ($batch as $p) {
            $digests[] = [
                'url' => $p['url'],
                'title' => $p['title'],
                'text' => function_exists('mb_substr') ? mb_substr($p['text'], 0, 3500) : substr($p['text'], 0, 3500),
            ];
        }
        $user = wp_json_encode([
            'site_base_url' => $base_url,
            'pages' => $digests,
            'instruction' => 'Extract BKBS entities for this business.',
        ]);
        $raw = $llm->chat(self::SYSTEM, (string) $user);
        $json = $this->parse_json($raw);
        $list = $json['entities'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item) || empty($item['name']) || empty($item['entity_type'])) {
                continue;
            }
            $item['source'] = 'llm';
            $out[] = $item;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function ent(string $type, string $name, string $desc, string $url, string $source): array
    {
        return [
            'entity_type' => $type,
            'name' => $name,
            'description' => $desc,
            'properties' => new stdClass(),
            'relationships' => [],
            'evidence' => [['url' => $url, 'snippet' => substr($desc, 0, 200), 'kind' => 'text']],
            'trust_level' => 'medium',
            'source' => $source,
        ];
    }

    /** @return array<string,mixed> */
    private function parse_json(string $content): array
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $m)) {
            $content = trim($m[1]);
        }
        $data = json_decode($content, true);
        if (is_array($data)) {
            return $data;
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }
}
