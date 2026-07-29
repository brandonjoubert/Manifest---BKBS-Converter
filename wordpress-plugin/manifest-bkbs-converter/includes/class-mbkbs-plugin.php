<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MBKBS_Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function init(): void
    {
        add_action('init', [MBKBS_Publisher::class, 'register_rewrites']);
        add_filter('query_vars', [MBKBS_Publisher::class, 'query_vars']);
        add_action('template_redirect', [MBKBS_Publisher::class, 'template_redirect']);

        if (is_admin()) {
            require_once MBKBS_PLUGIN_DIR . 'admin/class-mbkbs-admin.php';
            MBKBS_Admin::instance()->hooks();
        }
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /** @return array<string,string> */
    public static function entity_types(): array
    {
        return [
            'business_identity' => __('Business Identity', 'manifest-bkbs'),
            'product_service' => __('Products & Services', 'manifest-bkbs'),
            'capability' => __('Capabilities', 'manifest-bkbs'),
            'expertise' => __('Expertise', 'manifest-bkbs'),
            'facility_served' => __('Facilities Served', 'manifest-bkbs'),
            'operational_problem' => __('Operational Problems', 'manifest-bkbs'),
            'project' => __('Projects', 'manifest-bkbs'),
            'knowledge_article' => __('Knowledge Articles', 'manifest-bkbs'),
            'policy' => __('Policies', 'manifest-bkbs'),
            'team' => __('Team', 'manifest-bkbs'),
            'asset' => __('Assets', 'manifest-bkbs'),
            'relationship' => __('Relationships', 'manifest-bkbs'),
        ];
    }
}
