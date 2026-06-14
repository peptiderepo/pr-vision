<?php
/**
 * PR Vision top-level admin page.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * PR Vision top-level admin page.
 *
 * Registers the "PR Vision" menu item (manage_options), enqueues Chart.js
 * and the dashboard stylesheet, handles the "Run now" POST action (nonce +
 * capability), and delegates rendering to the registered panels via
 * PRV_Collector_Registry.
 *
 * Who triggers: PRV_Plugin::init() → is_admin() guard.
 * Dependencies: PRV_Collector_Registry, PRV_Probe_Runner.
 *
 * @see class-prv-plugin.php              — Calls register_hooks().
 * @see class-prv-collector-registry.php  — Provides panels.
 * @see class-prv-probe-runner.php        — Invoked by the Run now action.
 * @see class-prv-ai-visibility-panel.php — Panel renderer, consumes CSS added here.
 * @see ARCHITECTURE.md                   — §Admin dashboard.
 * @package PrVision
 */
class PRV_Admin_Page {

	/**
	 * Admin menu slug.
	 */
	const MENU_SLUG = 'pr-vision';

	/**
	 * Nonce action for the Run now form.
	 */
	const NONCE_ACTION = 'prv_run_now';

	/**
	 * Nonce field name.
	 */
	const NONCE_FIELD = 'prv_nonce';

	/**
	 * Register WordPress admin hooks.
	 *
	 * Side effects: Adds admin_menu and admin_post actions.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_prv_run_now', array( $this, 'handle_run_now' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the "PR Vision" top-level menu page.
	 *
	 * Side effects: Adds a WP admin menu entry.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_menu_page(
			__( 'PR Vision', 'pr-vision' ),
			__( 'PR Vision', 'pr-vision' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-chart-line',
			58
		);
	}

	/**
	 * Enqueue Chart.js and dashboard styles on the PR Vision page only.
	 *
	 * Chart.js is loaded from the jsdelivr CDN. The dashboard panel degrades
	 * gracefully when the CDN is blocked (typeof guard + .prv-noscript fallback).
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_script(
			'prv-chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
			array(),
			'4.4.3',
			true
		);

		wp_add_inline_style( 'wp-admin', $this->get_dashboard_css() );
	}

	/**
	 * Handle the "Run now" POST action.
	 *
	 * Validates nonce and capability before dispatching to PRV_Probe_Runner.
	 * Redirects back to the admin page with a status query arg.
	 *
	 * Side effects: Triggers a full probe run; redirects.
	 *
	 * @return void
	 */
	public function handle_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'pr-vision' ), 403 );
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'pr-vision' ), 403 );
		}

		$runner = new PRV_Probe_Runner();
		$counts = $runner->run();

		$redirect = add_query_arg(
			array(
				'page'         => self::MENU_SLUG,
				'prv_run_done' => 1,
				'prv_probed'   => (int) $counts['probed'],
				'prv_skipped'  => (int) ( $counts['skipped_budget'] + $counts['skipped_error'] ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the full PR Vision admin page.
	 *
	 * Side effects: Outputs HTML directly.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'pr-vision' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PR Vision — AI Visibility', 'pr-vision' ) . '</h1>';

		$this->render_run_done_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="prv_run_now">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		submit_button( __( 'Run now', 'pr-vision' ), 'secondary', 'prv_run_now_btn', false );
		echo '</form>';

		$registry = PRV_Collector_Registry::instance();

		foreach ( $registry->get_collectors() as $key => $collector ) {
			$panel = $registry->get_panel( $key );
			if ( null === $panel ) {
				continue;
			}
			$data = $collector->collect();
			$panel->render( $data );
		}

		echo '</div>';
	}

	/**
	 * Render a success or partial notice after a Run now redirect.
	 *
	 * @return void
	 */
	private function render_run_done_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['prv_run_done'] ) ) {
			return;
		}
		$probed  = isset( $_GET['prv_probed'] ) ? absint( wp_unslash( $_GET['prv_probed'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, Generic.Formatting.MultipleStatementAlignment.NotSameWarning, Generic.Formatting.MultipleStatementAlignment.IncorrectWarning
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$skipped = isset( $_GET['prv_skipped'] ) ? absint( wp_unslash( $_GET['prv_skipped'] ) ) : 0;

		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: probed count, 2: skipped count */
				__( 'Run complete: %1$d probes collected, %2$d skipped (budget or error).', 'pr-vision' ),
				$probed,
				$skipped
			)
		);
		echo '</p></div>';
	}

	/**
	 * Return the dashboard CSS for the PR Vision admin page.
	 *
	 * Covers two concerns:
	 * 1. WP admin wrapper chrome (injected here; fires on BOTH PR Vision screens
	 *    because the enqueue_assets guard matches 'pr-vision' in the hook suffix):
	 *    #wpcontent / #wpbody-content are set to the dark page bg so no white
	 *    gutter/strip shows around or above the dark UI.
	 * 2. Component tokens (.prv-*) — unchanged from prior versions.
	 *
	 * This inline style block is attached to wp-admin (always loaded on admin
	 * pages) via wp_add_inline_style() and therefore only present on the two
	 * PR Vision screens. It must NOT be registered globally.
	 *
	 * @return string CSS string.
	 */
	private function get_dashboard_css(): string {
		return '
/* === PR Vision — WP admin chrome: kill white wrappers (scoped via conditional enqueue) === */
#wpcontent{background:#14181C;padding-left:0;}
#wpbody-content{background:#14181C;min-height:calc(100vh - 32px);padding-bottom:40px;}
#wpbody{background:#14181C;}
.wrap>h1,.prv-settings-wrap~*>h1,.wp-heading-inline{color:#EEF2F5;}
.notice.prv-proxy-note{background:#1C2228;border-left-color:#34C0CA;color:#EEF2F5;}
.notice.prv-proxy-note p{color:#C2CCD6;}
/* === PR Vision dashboard — "Assay" palette (dark-adapted) === */
.prv-bento{display:grid;grid-template-columns:1.25fr 1fr 1fr;gap:16px;margin:18px 0 22px;}
@media(max-width:820px){.prv-bento{grid-template-columns:1fr;}}
.prv-tile{background:#1C2228;border:1px solid #3A4651;border-radius:12px;padding:20px 22px;position:relative;min-height:140px;display:flex;flex-direction:column;}
.prv-tile-label{font-size:11.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#9AA7B2;margin-bottom:6px;}
.prv-tile-big{font-size:42px;font-weight:700;color:#EEF2F5;line-height:1;font-variant-numeric:tabular-nums;margin-top:auto;}
.prv-tile-big small{font-size:16px;font-weight:600;color:#9AA7B2;margin-left:3px;}
.prv-tile-sub{font-size:12.5px;color:#9AA7B2;margin-top:8px;}
.prv-tile-delta{font-size:12.5px;font-weight:600;margin-top:8px;}
.prv-delta--up{color:#9BE635;} .prv-delta--down{color:#FF6B5E;} .prv-delta--flat{color:#9AA7B2;}
/* health pill */
.prv-health-pill{position:absolute;top:14px;right:14px;display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:3px 9px 3px 7px;border-radius:999px;}
.prv-health-dot{width:7px;height:7px;border-radius:50%;flex:0 0 7px;}
.prv-health--ok{background:rgba(155,230,53,.15);color:#9BE635;} .prv-health--ok .prv-health-dot{background:#9BE635;}
.prv-health--warn{background:rgba(255,157,77,.15);color:#FFB36E;} .prv-health--warn .prv-health-dot{background:#FF9D4D;}
.prv-health--neutral{background:rgba(136,147,160,.14);color:#B4BDC7;} .prv-health--neutral .prv-health-dot{background:#8893A0;}
/* cost tile */
.prv-cost-row{display:flex;align-items:baseline;gap:4px;margin-top:auto;}
.prv-cost-big{font-size:28px;font-weight:700;color:#EEF2F5;font-variant-numeric:tabular-nums;}
.prv-cost-cap{font-size:13px;color:#9AA7B2;}
.prv-meter{height:10px;border-radius:999px;background:#2A333C;margin-top:8px;border:1px solid #333D47;overflow:hidden;}
.prv-meter>span{display:block;height:100%;border-radius:999px;background:#34C0CA;transition:width .3s ease;}
.prv-meter--capped>span{background:#FF9D4D;}
.prv-trunc-badge{display:flex;align-items:flex-start;gap:7px;margin-top:10px;background:rgba(255,157,77,.15);border:1px solid rgba(255,157,77,.42);border-radius:6px;padding:8px 10px;font-size:12px;color:#EEF2F5;line-height:1.4;}
.prv-trunc-icon{color:#FFB36E;font-size:14px;flex:0 0 16px;margin-top:1px;}
.prv-trunc-badge b{color:#FFB36E;}
/* last run tile */
.prv-tile-ts{font-size:20px;font-weight:700;color:#EEF2F5;margin-top:auto;line-height:1.2;}
.prv-tile-meta{font-size:12.5px;color:#9AA7B2;margin-top:6px;}
/* cards */
.prv-card{background:#1C2228;border:1px solid #3A4651;border-radius:12px;margin:0 0 20px;}
.prv-card-head{display:flex;align-items:center;justify-content:space-between;padding:18px 22px 0;}
.prv-card-head h2{font-size:16px;font-weight:600;color:#EEF2F5;margin:0;}
.prv-card-body{padding:10px 22px 20px;}
/* chart */
.prv-chartbox{position:relative;height:300px;max-height:300px;padding:14px 8px 0;overflow:hidden;}
.prv-chartbox canvas{max-height:286px;}
.prv-chart-fallback{display:none;height:100%;align-items:center;justify-content:center;text-align:center;color:#9AA7B2;font-size:13px;padding:0 24px;}
.prv-chartbox.prv-noscript .prv-chart-fallback{display:flex;}
.prv-chartbox.prv-noscript canvas{display:none;}
/* chart legend */
.prv-chartcap{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:8px 22px 4px;font-size:12.5px;color:#9AA7B2;}
.prv-chartcap-item{display:inline-flex;align-items:center;gap:6px;}
.prv-leg-solid{width:20px;height:0;border-top:3px solid #34C0CA;display:inline-block;}
.prv-leg-dash{width:20px;height:0;border-top:2px dashed #B6F25A;display:inline-block;}
.prv-leg-cfg{display:inline-flex;align-items:center;gap:3px;}
.prv-leg-cfg::before{content:"";width:0;height:14px;border-left:2px dashed #FFB36E;display:inline-block;}
.prv-leg-cfg::after{content:"";width:8px;height:8px;border-radius:50%;background:#FF9D4D;display:inline-block;}
/* config change note */
.prv-cfg-note{display:flex;gap:10px;align-items:flex-start;margin:8px 22px 14px;background:rgba(255,157,77,.15);border:1px solid rgba(255,157,77,.42);border-radius:8px;padding:11px 14px;color:#EEF2F5;font-size:13px;line-height:1.45;}
.prv-cfg-note-icon{color:#FFB36E;font-style:normal;flex:0 0 16px;margin-top:1px;}
.prv-cfg-note b{color:#FFB36E;}
/* standings table */
.prv-standings{margin:0;}
.prv-status{display:inline-flex;align-items:center;gap:4px;font-size:12.5px;font-weight:600;padding:2px 8px;border-radius:999px;}
.prv-status--cited{background:rgba(155,230,53,.15);color:#9BE635;}
.prv-status--not-yet{background:rgba(136,147,160,.14);color:#B4BDC7;}
/* proxy note */
.prv-proxy-note{margin:10px 0;}
';
	}
}
