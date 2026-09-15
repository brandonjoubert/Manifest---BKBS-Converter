<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claim Ledger Stage 7: authenticated query API (REST).
 */
final class MBKBS_Query_API
{
    public static function register(): void
    {
        register_rest_route(
            'mbkbs/v1',
            '/entities/(?P<id>[a-zA-Z0-9-]+)/claims',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_claims'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'id' => ['required' => true, 'type' => 'string'],
                    'as_of' => ['required' => false, 'type' => 'string'],
                ],
            ]
        );
        register_rest_route(
            'mbkbs/v1',
            '/entities/(?P<id>[a-zA-Z0-9-]+)',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_entity'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'id' => ['required' => true, 'type' => 'string'],
                    'as_of' => ['required' => false, 'type' => 'string'],
                ],
            ]
        );
    }

    public static function ensure_token(): string
    {
        $token = MBKBS_Database::get_setting('api.token', '');
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            MBKBS_Database::set_setting('api.token', $token);
        }
        return $token;
    }

    /**
     * @param WP_REST_Request $request
     */
    public static function permission($request): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $expected = self::ensure_token();
        $provided = '';
        $auth = (string) $request->get_header('authorization');
        if (stripos($auth, 'Bearer ') === 0) {
            $provided = trim(substr($auth, 7));
        }
        if ($provided === '') {
            $provided = trim((string) $request->get_header('x-api-key'));
        }
        return $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function get_entity($request)
    {
        $id = (string) $request['id'];
        $asOf = trim((string) $request->get_param('as_of'));
        $asOf = $asOf !== '' ? $asOf : null;
        $resolved = MBKBS_Resolver::resolve_entity($id, $asOf);
        if ($resolved === null) {
            return new WP_Error('mbkbs_not_found', 'Entity not found', ['status' => 404]);
        }
        return rest_ensure_response($resolved);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function get_claims($request)
    {
        global $wpdb;
        $id = (string) $request['id'];
        $entities = MBKBS_Database::entities_table();
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$entities} WHERE id = %s", $id));
        if (!$exists) {
            return new WP_Error('mbkbs_not_found', 'Entity not found', ['status' => 404]);
        }
        $claims = MBKBS_Database::claims_table();
        $asOf = trim((string) $request->get_param('as_of'));
        if ($asOf !== '') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$claims} WHERE entity_id = %s AND (
                        (approved_at IS NOT NULL AND approved_at <= %s)
                        OR (approved_at IS NULL AND created_at <= %s)
                    ) ORDER BY id ASC",
                    $id,
                    $asOf,
                    $asOf
                ),
                ARRAY_A
            ) ?: [];
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$claims} WHERE entity_id = %s ORDER BY id ASC", $id),
                ARRAY_A
            ) ?: [];
        }
        return rest_ensure_response($rows);
    }
}
