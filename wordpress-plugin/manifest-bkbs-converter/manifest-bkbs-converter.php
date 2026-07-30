<?php
/**
 * Plugin Name:       Manifest BKBS Converter
 * Plugin URI:        https://github.com/brandonjoubert/Manifest---BKBS-Converter
 * Description:       Standalone WordPress edition of Manifest BKBS Converter. Scan sites, human-verify business knowledge entities, and publish agent-ready layers (llms.txt, graph.json, schema.org) for the dual-purpose web.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Manifest BKBS Contributors
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       manifest-bkbs
 *
 * This plugin is a separate product path from the Python and PHP shared-hosting editions.
 * It does not depend on those codebases at runtime.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('MBKBS_VERSION', '0.1.0');
define('MBKBS_PLUGIN_FILE', __FILE__);
define('MBKBS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MBKBS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MBKBS_DB_VERSION', '1');

require_once MBKBS_PLUGIN_DIR . 'includes/class-mbkbs-database.php';
require_once MBKBS_PLUGIN_DIR . 'includes/class-mbkbs-crawler.php';
require_once MBKBS_PLUGIN_DIR . 'includes/class-mbkbs-extractor.php';
require_once MBKBS_PLUGIN_DIR . 'includes/class-mbkbs-llm.php';
require_once MBKBS_PLUGIN_DIR . 'includes/class-mbkbs-publisher.php';
require_once MBKBS_PLUGIN_DIR . 'includes/class-mbkbs-plugin.php';

register_activation_hook(__FILE__, ['MBKBS_Database', 'activate']);
register_deactivation_hook(__FILE__, ['MBKBS_Plugin', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    MBKBS_Plugin::instance()->init();
});
