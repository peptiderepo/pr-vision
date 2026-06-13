<?php
/**
 * Plugin Name: Peptide GEO Monitor
 * Plugin URI:  https://peptiderepo.com
 * Description: Weekly server-side LLM probes for AI-visibility (GEO) tracking. Records whether peptiderepo.com is cited by LLMs across core peptides, stores time-series, and renders an admin trendline + standings table.
 * Version:     0.1.0
 * Author:      peptiderepo
 * Author URI:  https://peptiderepo.com
 * License:     GPL-2.0-or-later
 * Text Domain: peptide-geo-monitor
 * Requires PHP: 8.1
 *
 * @see ARCHITECTURE.md — Full data flow and file tree.
 * @see CONVENTIONS.md  — Naming patterns and extension guide.
 * @see CONTEXT.md      — Domain glossary incl. the visibility score formula.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Constants ────────────────────────────────────────────────────────── */

define( 'PGM_VERSION', '0.1.0' );
define( 'PGM_PLUGIN_FILE', __FILE__ );
define( 'PGM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PGM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PGM_SCHEMA_VERSION', 1 );

/** @var int Maximum HTTP retries for LLM API calls. */
define( 'PGM_MAX_RETRIES', 3 );

/** @var int API request timeout in seconds. */
define( 'PGM_API_TIMEOUT_SECONDS', 60 );

/** @var int Base backoff delay (seconds) between retries. */
define( 'PGM_RETRY_BASE_DELAY_SECONDS', 2 );

/** @var float Default monthly budget cap in USD. */
define( 'PGM_DEFAULT_MONTHLY_BUDGET_USD', 5.0 );

/** @var string WP-Cron hook name for the weekly probe run. */
define( 'PGM_CRON_HOOK', 'pgm_weekly_probe' );

/** @var string The site we are tracking citations for. */
define( 'PGM_TARGET_DOMAIN', 'peptiderepo.com' );

/* ── Autoloader ───────────────────────────────────────────────────────── */

require_once PGM_PLUGIN_DIR . 'includes/core/class-pgm-autoloader.php';
PGM_Autoloader::register();

/* ── Activation / Deactivation ────────────────────────────────────────── */

register_activation_hook( __FILE__, array( 'PGM_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PGM_Deactivator', 'deactivate' ) );

/* ── Boot ──────────────────────────────────────────────────────────────── */

add_action(
	'plugins_loaded',
	static function (): void {
		$plugin = new PGM_Plugin();
		$plugin->init();
	}
);
