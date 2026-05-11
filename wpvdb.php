<?php
/**
 * Plugin Name: WPVDB - WordPress Vector Database
 * Plugin URI:  https://github.com/automattic/wpvdb
 * Description: Transform WordPress into a vector database with native or fallback support for vector columns, chunking, embedding, and REST endpoints.
 * Version:     1.0.14
 * Author:      Automattic, James LePage
 * Author URI:  https://automattic.com
 * Text Domain: wpvdb
 * Domain Path: /languages/
 * Network:     false
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 *
 * @package WPVDB
 */

defined('ABSPATH') || exit; // No direct access.

// Define plugin version and constants.
define('WPVDB_VERSION', '1.0.14');
define('WPVDB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPVDB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPVDB_PLUGIN_FILE', __FILE__);

// Optionally define a default dimension for your embeddings (e.g., 1536).
if (!defined('WPVDB_DEFAULT_EMBED_DIM')) {
    define('WPVDB_DEFAULT_EMBED_DIM', 768);
}

/**
 * Whether wpvdb is running on WordPress Playground or any SQLite drop in.
 *
 * Detection sentinel: the sqlite-database-integration drop in
 * (wp-content/db.php, included by WordPress before plugins load) defines
 * both `DB_ENGINE` and `DATABASE_TYPE` to `'sqlite'`. Reliable on wp-now
 * (where the sqlite plugin lives under mu-plugins/<subdir>/ and WP does
 * not recurse, so SQLITE_MAIN_FILE is never auto-defined) AND on
 * production Playground (where the drop in is mounted at wp-content/db.php).
 *
 * `SQLITE_MAIN_FILE` is intentionally NOT a standalone signal: a MySQL site
 * that has the sqlite-database-integration plugin installed but not active
 * would otherwise be misdetected. The presence of `DB_ENGINE=sqlite` is the
 * authoritative "this connection is going through SQLite" signal.
 *
 * Defined here (before the Composer autoloader and Action Scheduler require)
 * so that downstream wpvdb.php file scope code can gate on it, including the
 * future Action Scheduler bootstrap gate below.
 *
 * Lives in the global namespace mirroring wpvdb_has_action_scheduler().
 *
 * @return bool
 */
if (!function_exists('wpvdb_is_playground_or_sqlite')) {
    function wpvdb_is_playground_or_sqlite() {
        if (defined('DB_ENGINE') && DB_ENGINE === 'sqlite') {
            return true;
        }
        if (defined('DATABASE_TYPE') && DATABASE_TYPE === 'sqlite') {
            return true;
        }
        return false;
    }
}

// API Keys can be defined in wp-config.php for better security and environment-specific configuration
// Example:
// define('WPVDB_OPENAI_API_KEY', 'your-openai-api-key');
// define('WPVDB_AUTOMATTIC_API_KEY', 'your-automattic-api-key');

// Include the Composer autoloader
if (file_exists(WPVDB_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once WPVDB_PLUGIN_DIR . 'vendor/autoload.php';
}

// Initialize Action Scheduler.
// Playground / SQLite: skip the bootstrap entirely. AS would register init
// hooks (vendor/.../abstracts/ActionScheduler.php:196), schedule queue-runner
// kickoffs (vendor/.../ActionScheduler_QueueRunner.php:70), and enqueue
// recurring scheduler actions (vendor/.../ActionScheduler_RecurringActionScheduler.php:25)
// that produce inert rows in `wp_actionscheduler_actions`. With no loopback
// and cron disabled, none of these can drain. Every wpvdb-side AS call is
// already gated by edit 7; any missed call surfaces here as a fatal
// "undeclared function" rather than a silently accumulating row, which is the
// intended regression signal.
if (! wpvdb_is_playground_or_sqlite()
    && file_exists(WPVDB_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php')) {
    require_once WPVDB_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Include class files.
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-utils.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-logger.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-security.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-cache.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-database.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-maintenance.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-activation.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-models.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-providers.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-core.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-rest.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-query.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-settings.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-queue.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-admin.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-plugin.php';

/**
 * Global helper: whether Action Scheduler is available for scheduling.
 *
 * Must live in the global namespace (not WPVDB\) because WPVDB_Queue checks it
 * via the unqualified function_exists('wpvdb_has_action_scheduler'). If this
 * function is missing or namespaced, push_batch_to_queue falls through to the
 * wp_options fallback queue silently.
 *
 * @return bool
 */
if (!function_exists('wpvdb_has_action_scheduler')) {
    function wpvdb_has_action_scheduler() {
        return class_exists('ActionScheduler') && function_exists('as_schedule_single_action');
    }
}

// Playground / SQLite: force the LONGTEXT JSON fallback schema before activation
// reads `are_fallbacks_enabled()`. The filter callback is cached on first call
// (`class-wpvdb-database.php:80-87`), so registering here, before the activation
// hook can fire, is load bearing. Use the global `wpvdb_is_playground_or_sqlite()`
// helper (defined above) rather than checking `SQLITE_MAIN_FILE` directly,
// because wp-now defines only `DB_ENGINE='sqlite'` early enough for plugin load.
add_filter('wpvdb_enable_fallbacks', function ($enabled) {
    if (wpvdb_is_playground_or_sqlite()) {
        return true;
    }
    return $enabled;
});

// Playground / SQLite: opt in to the Service Worker CORS proxy forwarding the
// `Authorization` header. By default the proxy strips it
// (packages/playground/php-cors-proxy/cors-proxy.php:170) and the SW only
// forwards credentials when the client sets
// `X-Cors-Proxy-Allowed-Request-Headers: authorization` (lowercase, checked
// at packages/php-wasm/web-service-worker/src/lib/fetch-with-cors-proxy.ts:69-75).
// Without this filter, every Bearer-authenticated embedding request to
// api.openai.com or public-api.wordpress.com returns 401 inside Playground.
//
// Hook: `http_request_args` is the WP HTTP API request-shaping filter and merges
// headers into the outbound request. `pre_http_request` would short-circuit and
// is the wrong hook.
//
// Scope: only inject for known embedding-provider hosts; do not touch unrelated
// outbound requests from WordPress core or other plugins.
add_filter('http_request_args', function ($args, $url) {
    if (! wpvdb_is_playground_or_sqlite()) {
        return $args;
    }
    if (! is_string($url) || $url === '') {
        return $args;
    }
    $host = parse_url($url, PHP_URL_HOST);
    $allowed_hosts = [
        'api.openai.com',
        'public-api.wordpress.com',
    ];
    if (! in_array($host, $allowed_hosts, true)) {
        return $args;
    }
    if (! isset($args['headers']) || ! is_array($args['headers'])) {
        $args['headers'] = [];
    }
    // The Service Worker parses this header with a bare `split(',')` and
    // `includes('authorization')` against an exact lowercase token (see
    // packages/php-wasm/web-service-worker/src/lib/fetch-with-cors-proxy.ts:70),
    // so each entry must be lowercase with no surrounding whitespace and the
    // join separator must be a plain comma (no space). Otherwise an earlier
    // filter that set, for example, `cookie` would produce `cookie, authorization`
    // which splits to [`cookie`, ` authorization`] and the SW would still strip
    // the Authorization header because the token has a leading space.
    $existing = isset($args['headers']['X-Cors-Proxy-Allowed-Request-Headers'])
        ? (string) $args['headers']['X-Cors-Proxy-Allowed-Request-Headers']
        : '';
    $opt_in_list = [];
    foreach (explode(',', $existing) as $token) {
        $token = strtolower(trim($token));
        if ($token !== '' && ! in_array($token, $opt_in_list, true)) {
            $opt_in_list[] = $token;
        }
    }
    if (! in_array('authorization', $opt_in_list, true)) {
        $opt_in_list[] = 'authorization';
    }
    $args['headers']['X-Cors-Proxy-Allowed-Request-Headers'] = implode(',', $opt_in_list);
    return $args;
}, 10, 2);

// Get the plugin instance
$wpvdb_plugin = \WPVDB\Plugin::get_instance();

/**
 * Activation hook.
 */
register_activation_hook(__FILE__, [$wpvdb_plugin, 'activate']);

/**
 * Deactivation hook.
 */
register_deactivation_hook(__FILE__, [$wpvdb_plugin, 'deactivate']);

/**
 * Plugin init: bootstrap the core and REST APIs. 
 */
add_action('plugins_loaded', [$wpvdb_plugin, 'init']);

// Add deactivation notice
add_action('admin_notices', [$wpvdb_plugin, 'deactivated_notice']);

// Add deactivation action
add_action('wpvdb_maybe_deactivate_plugin', [$wpvdb_plugin, 'maybe_deactivate_plugin']);

// Add action for processing fallback queue.
// Add action for running action scheduler more frequently in admin.
// Both skipped under Playground / SQLite: cron is disabled, loopback is
// blocked, the fallback queue option would accumulate without ever draining.
if (! wpvdb_is_playground_or_sqlite()) {
    add_action('wpvdb_process_fallback_queue', [$wpvdb_plugin, 'process_fallback_queue']);
    add_action('init', [$wpvdb_plugin, 'maybe_run_action_scheduler']);
}

// Add vector index to existing tables during plugin updates
add_action('plugins_loaded', function() {
    // Get current plugin version
    $current_version = get_option('wpvdb_version', '0.0.0');
    
    // If version has changed, run update procedures
    if (version_compare($current_version, WPVDB_VERSION, '<')) {
        // Add vector index to existing tables
        \WPVDB\Activation::add_vector_index_to_existing_table();
        \WPVDB\Settings::migrate_stored_settings();
        
        // Update stored version
        update_option('wpvdb_version', WPVDB_VERSION);
    }
});
