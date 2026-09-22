<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claim Ledger Stage 9: optional host capability layer.
 * Pure methods do not touch WordPress. REST handlers do.
 */
final class MBKBS_Capability
{
    public const MAX_FACTS = 50;
    public const SKILL_ID = 'search-approved-facts';
    public const PROTOCOL_VERSION = '0.3.0';
    public const MCP_PROTOCOL = '2025-06-18';

    public static function register(): void
    {
        register_rest_route('mbkbs/v1', '/agent-card', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_card'],
            'permission_callback' => [self::class, 'permission_card'],
        ]);
        register_rest_route('mbkbs/v1', '/ask', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_ask'],
            'permission_callback' => [self::class, 'permission_facts'],
        ]);
        register_rest_route('mbkbs/v1', '/mcp', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_mcp'],
            'permission_callback' => [self::class, 'permission_facts'],
        ]);
        register_rest_route('mbkbs/v1', '/a2a', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_a2a'],
            'permission_callback' => [self::class, 'permission_facts'],
        ]);
    }

    public static function enabled(): bool
    {
        return MBKBS_Database::get_setting('capability.enabled', '0') === '1';
    }

    /** @param WP_REST_Request $request */
    public static function permission_card($request): bool
    {
        unset($request);
        return true;
    }

    /** @param WP_REST_Request $request */
    public static function permission_facts($request)
    {
        if (!self::enabled()) {
            return true;
        }
        return MBKBS_Query_API::permission($request);
    }

    /** @param WP_REST_Request $request */
    public static function rest_card($request)
    {
        unset($request);
        if (!self::enabled()) {
            return new WP_Error('mbkbs_off', 'Not found', ['status' => 404]);
        }
        $name = (string) get_bloginfo('name');
        if ($name === '') {
            $name = 'BKBS site';
        }
        return rest_ensure_response(self::agentCard($name, (string) rest_url('mbkbs/v1/a2a')));
    }

    /** @param WP_REST_Request $request */
    public static function rest_ask($request)
    {
        if (!self::enabled()) {
            return new WP_Error('mbkbs_off', 'Not found', ['status' => 404]);
        }
        $body = $request->get_json_params();
        $query = is_array($body) && array_key_exists('query', $body) && is_string($body['query']) ? $body['query'] : null;
        $out = self::answerAsk(self::public_entities(), $query, (string) home_url('/'));
        if ($out['status'] !== 200) {
            return new WP_Error('mbkbs_ask', (string) ($out['body']['error'] ?? 'Bad request'), ['status' => $out['status']]);
        }
        return rest_ensure_response($out['body']);
    }

    /** @param WP_REST_Request $request */
    public static function rest_mcp($request)
    {
        if (!self::enabled()) {
            return new WP_Error('mbkbs_off', 'Not found', ['status' => 404]);
        }
        $body = $request->get_json_params();
        $out = self::answerMcp(self::public_entities(), is_array($body) ? $body : null);
        return rest_ensure_response($out['body']);
    }

    /** @param WP_REST_Request $request */
    public static function rest_a2a($request)
    {
        if (!self::enabled()) {
            return new WP_Error('mbkbs_off', 'Not found', ['status' => 404]);
        }
        $body = $request->get_json_params();
        $out = self::answerA2a(self::public_entities(), is_array($body) ? $body : null);
        return rest_ensure_response($out['body']);
    }

    /** @return list<array<string, mixed>> */
    private static function public_entities(): array
    {
        return MBKBS_Resolver::resolve_site(null, false);
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    public static function publicFact(array $entity): array
    {
        $props = $entity['properties'] ?? [];
        if (!is_array($props)) {
            $props = [];
        }
        return [
            'id' => (string) ($entity['id'] ?? ''),
            'entity_type' => (string) ($entity['entity_type'] ?? ''),
            'name' => (string) ($entity['name'] ?? ''),
            'description' => (string) ($entity['description'] ?? ''),
            'properties' => $props,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    public static function searchApproved(array $entities, string $query): array
    {
        $facts = array_map(static fn($e) => self::publicFact($e), $entities);
        $tokens = array_values(array_filter(preg_split('/\s+/', strtolower(trim($query))) ?: []));
        if ($tokens === []) {
            return array_slice($facts, 0, self::MAX_FACTS);
        }
        $out = [];
        foreach ($facts as $fact) {
            $hay = self::haystack($fact);
            $ok = true;
            foreach ($tokens as $token) {
                if (!str_contains($hay, $token)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = $fact;
                if (count($out) >= self::MAX_FACTS) {
                    break;
                }
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $fact */
    private static function haystack(array $fact): string
    {
        $blob = json_encode($fact['properties'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        return strtolower($fact['name'] . ' ' . $fact['description'] . ' ' . $fact['entity_type'] . ' ' . $blob);
    }

    /** @return array<string, mixed> */
    public static function agentCard(string $name, string $endpointUrl): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'name' => $name,
            'description' => 'Answers from approved BKBS claims only. Does not invent facts and does not read pending review items.',
            'url' => $endpointUrl,
            'version' => '1.0.0',
            'capabilities' => [
                'streaming' => false,
                'pushNotifications' => false,
                'stateTransitionHistory' => false,
            ],
            'defaultInputModes' => ['text/plain'],
            'defaultOutputModes' => ['text/plain', 'application/json'],
            'skills' => [[
                'id' => self::SKILL_ID,
                'name' => 'Search approved facts',
                'description' => 'Return approved resolved claims that match the question.',
                'tags' => ['bkbs', 'knowledge'],
                'examples' => ['What is the published phone number?'],
            ]],
            'securitySchemes' => [
                'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
            ],
            'security' => [['bearer' => []]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @param array<string, mixed>|null $body
     * @return array{status:int, body:array<string, mixed>}
     */
    public static function answerA2a(array $entities, ?array $body): array
    {
        if ($body === null) {
            return ['status' => 200, 'body' => self::rpcError(null, -32700, 'Parse error')];
        }
        $reqId = $body['id'] ?? null;
        $method = $body['method'] ?? '';
        if (!is_string($method) || $method === '') {
            return ['status' => 200, 'body' => self::rpcError($reqId, -32600, 'Invalid Request')];
        }
        if ($method !== 'message/send') {
            return ['status' => 200, 'body' => self::rpcError($reqId, -32601, 'Method not found')];
        }
        $query = self::messageText($body['params'] ?? null);
        $facts = self::searchApproved($entities, $query);
        $task = [
            'id' => self::uuid(),
            'contextId' => self::uuid(),
            'status' => ['state' => 'completed'],
            'artifacts' => [[
                'artifactId' => self::uuid(),
                'name' => 'approved-facts',
                'parts' => [
                    ['kind' => 'text', 'text' => self::summary($facts)],
                    ['kind' => 'data', 'data' => ['query' => $query, 'results' => $facts]],
                ],
            ]],
        ];
        return ['status' => 200, 'body' => self::rpcResult($reqId, $task)];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @param array<string, mixed>|null $body
     * @return array{status:int, body:array<string, mixed>}
     */
    public static function answerMcp(array $entities, ?array $body): array
    {
        if ($body === null) {
            return ['status' => 200, 'body' => self::rpcError(null, -32700, 'Parse error')];
        }
        $reqId = $body['id'] ?? null;
        $method = $body['method'] ?? '';
        if (!is_string($method) || $method === '') {
            return ['status' => 200, 'body' => self::rpcError($reqId, -32600, 'Invalid Request')];
        }
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        if ($method === 'initialize') {
            $version = $params['protocolVersion'] ?? '';
            if (!is_string($version) || $version === '') {
                $version = self::MCP_PROTOCOL;
            }
            return ['status' => 200, 'body' => self::rpcResult($reqId, [
                'protocolVersion' => $version,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'bkbs-capability', 'version' => '1.0.0'],
            ])];
        }
        if ($method === 'notifications/initialized') {
            return ['status' => 200, 'body' => self::rpcResult($reqId, [])];
        }
        if ($method === 'tools/list') {
            return ['status' => 200, 'body' => self::rpcResult($reqId, ['tools' => self::mcpTools()])];
        }
        if ($method === 'tools/call') {
            $name = $params['name'] ?? '';
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            if ($name === 'search_approved') {
                $facts = self::searchApproved($entities, (string) ($arguments['query'] ?? ''));
            } elseif ($name === 'list_approved') {
                $facts = self::searchApproved($entities, '');
            } else {
                return ['status' => 200, 'body' => self::rpcError($reqId, -32602, 'Unknown tool')];
            }
            $payload = json_encode(['results' => $facts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"results":[]}';
            return ['status' => 200, 'body' => self::rpcResult($reqId, [
                'content' => [['type' => 'text', 'text' => $payload]],
                'isError' => false,
            ])];
        }
        return ['status' => 200, 'body' => self::rpcError($reqId, -32601, 'Method not found')];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return array{status:int, body:array<string, mixed>}
     */
    public static function answerAsk(array $entities, ?string $query, string $baseUrl): array
    {
        if ($query === null) {
            return ['status' => 400, 'body' => ['error' => 'query required']];
        }
        $facts = self::searchApproved($entities, $query);
        $results = [];
        foreach ($facts as $fact) {
            $results[] = [
                'name' => $fact['name'],
                'url' => $baseUrl,
                'description' => $fact['description'],
                'schema_object' => [
                    '@type' => 'Thing',
                    'name' => $fact['name'],
                    'description' => $fact['description'],
                    'identifier' => $fact['id'],
                ],
            ];
        }
        return ['status' => 200, 'body' => ['query' => $query, 'results' => $results]];
    }

    /** @param list<array<string, mixed>> $facts */
    private static function summary(array $facts): string
    {
        if ($facts === []) {
            return 'No approved fact matched. Nothing was invented.';
        }
        $lines = [count($facts) . ' approved fact(s).'];
        foreach ($facts as $fact) {
            $line = $fact['name'];
            if ($fact['description'] !== '') {
                $line .= ': ' . $fact['description'];
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private static function messageText(mixed $params): string
    {
        if (!is_array($params)) {
            return '';
        }
        $message = $params['message'] ?? null;
        if (is_string($message)) {
            return $message;
        }
        if (!is_array($message)) {
            return '';
        }
        $parts = $message['parts'] ?? [];
        $texts = [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_string($part)) {
                    $texts[] = $part;
                } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $texts[] = $part['text'];
                }
            }
        }
        if ($texts !== []) {
            return implode("\n", $texts);
        }
        return is_string($message['text'] ?? null) ? $message['text'] : '';
    }

    /** @return list<array<string, mixed>> */
    private static function mcpTools(): array
    {
        return [
            [
                'name' => 'search_approved',
                'description' => 'Search approved resolved claims. Does not invent facts.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'list_approved',
                'description' => 'List the approved public set for this site.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function rpcResult(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private static function rpcError(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
