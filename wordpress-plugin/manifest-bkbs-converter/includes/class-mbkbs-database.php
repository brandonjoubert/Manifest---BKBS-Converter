<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MBKBS_Database
{
    public static function sites_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mbkbs_sites';
    }

    public static function entities_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mbkbs_entities';
    }

    public static function settings_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mbkbs_settings';
    }

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sites = self::sites_table();
        $entities = self::entities_table();
        $settings = self::settings_table();

        $sql_sites = "CREATE TABLE {$sites} (
            id varchar(36) NOT NULL,
            name varchar(255) NOT NULL,
            base_url text NOT NULL,
            max_pages int NOT NULL DEFAULT 40,
            crawl_delay_ms int NOT NULL DEFAULT 300,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset};";

        $sql_entities = "CREATE TABLE {$entities} (
            id varchar(36) NOT NULL,
            site_id varchar(36) NOT NULL,
            external_key varchar(64) NOT NULL,
            entity_type varchar(64) NOT NULL,
            name varchar(512) NOT NULL,
            description longtext NULL,
            properties longtext NULL,
            relationships longtext NULL,
            evidence longtext NULL,
            version int NOT NULL DEFAULT 1,
            trust_level varchar(32) NOT NULL DEFAULT 'medium',
            source varchar(32) NOT NULL DEFAULT 'scan',
            status varchar(32) NOT NULL DEFAULT 'pending',
            notes longtext NULL,
            last_updated datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY site_ext (site_id, external_key),
            KEY site_status (site_id, status)
        ) {$charset};";

        $sql_settings = "CREATE TABLE {$settings} (
            setting_key varchar(128) NOT NULL,
            setting_value longtext NOT NULL,
            PRIMARY KEY  (setting_key)
        ) {$charset};";

        dbDelta($sql_sites);
        dbDelta($sql_entities);
        dbDelta($sql_settings);
        update_option('mbkbs_db_version', MBKBS_DB_VERSION);

        // Seed "this WordPress site" if empty.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sites}");
        if ($count === 0) {
            $id = self::uuid();
            $wpdb->insert(
                $sites,
                [
                    'id' => $id,
                    'name' => get_bloginfo('name') ?: 'This WordPress site',
                    'base_url' => untrailingslashit(home_url('/')),
                    'max_pages' => 40,
                    'crawl_delay_ms' => 200,
                    'created_at' => current_time('mysql', true),
                ],
                ['%s', '%s', '%s', '%d', '%d', '%s']
            );
        }

        flush_rewrite_rules();
    }

    public static function get_setting(string $key, string $default = ''): string
    {
        global $wpdb;
        $val = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT setting_value FROM ' . self::settings_table() . ' WHERE setting_key = %s',
                $key
            )
        );
        return is_string($val) ? $val : $default;
    }

    public static function set_setting(string $key, string $value): void
    {
        global $wpdb;
        $table = self::settings_table();
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT setting_key FROM {$table} WHERE setting_key = %s", $key)
        );
        if ($exists) {
            $wpdb->update($table, ['setting_value' => $value], ['setting_key' => $key], ['%s'], ['%s']);
        } else {
            $wpdb->insert($table, ['setting_key' => $key, 'setting_value' => $value], ['%s', '%s']);
        }
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function external_key(string $site_id, string $type, string $name): string
    {
        $norm = strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
        return substr(hash('sha256', $site_id . '|' . $type . '|' . $norm), 0, 32);
    }
}
