<?php
/**
 * Plugin Name: PR Vision
 * Plugin URI:  https://peptiderepo.com
 * Description: Weekly server-side LLM probes for AI-visibility (GEO) tracking. Records whether peptiderepo.com is cited by LLMs across core peptides, stores time-series, and renders an admin dashboard + settings UI.
 * Version:     0.2.2
 * Author:      peptiderepo
 * Author URI:  https://peptiderepo.com
 * License:     GPL-2.0-or-later
 * Text Domain: pr-vision
 * Requires PHP: 8.1
 *
 * @see ARCHITECTURE.md -- Full data flow and file tree.
 * @see CONVENTIONS.md  -- Naming patterns and extension guide.
 * @see CONTEXT.md      -- Domain glossary incl. the visibility score formula.
 * @package PrVision
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Constants ────────────────────────────────────────────────────────── */

define( 'PRV_VERSION', '0.2.2' );
define( 'PRV_PLUGIN_FILE', __FILE__ );
define( 'PRV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PRV_SCHEMA_VERSION', 2 );

/** Maximum HTTP retries for LLM API calls. @var int */
define( 'PRV_MAX_RETRIES', 3 );

/** API request timeout in seconds. @var int */
define( 'PRV_API_TIMEOUT_SECONDS', 60 );

/** Base backoff delay (seconds) between retries. @var int */
define( 'PRV_RETRY_BASE_DELAY_SECONDS', 2 );

/** Default monthly budget cap in USD. @var float */
define( 'PRV_DEFAULT_MONTHLY_BUDGET_USD', 5.0 );

/** WP-Cron hook name for the weekly probe run. @var string */
define( 'PRV_CRON_HOOK', 'prv_weekly_probe' );

/** The site we are tracking citations for. @var string */
define( 'PRV_TARGET_DOMAIN', 'peptiderepo.com' );

/* ── Autoloader ───────────────────────────────────────────────────────── */

require_once PRV_PLUGIN_DIR . 'includes/core/class-prv-autoloader.php';
PRV_Autoloader::register();

/* ── Activation / Deactivation ────────────────────────────────────────── */

register_activation_hook( __FILE__, array( 'PRV_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PRV_Deactivator', 'deactivate' ) );

/* ── Boot ──────────────────────────────────────────────────────────────── */

add_action(
	'plugins_loaded',
	static function (): void {
		$plugin = new PRV_Plugin();
		$plugin->init();
	}
);
