<?php
/** @package PrVision */
declare(strict_types=1);

/**
 * PR Vision top-level admin page.
 *
 * Registers the "PR Vision" menu item (manage_options), enqueues Chart.js,
 * handles the "Run now" POST action (nonce + capability), and delegates
 * rendering to the registered panels via PRV_Collector_Registry.
 *
 * Who triggers: PRV_Plugin::init() → is_admin() guard.
 * Dependencies: PRV_Collector_Registry, PRV_Probe_Runner.
 *
 * @see class-prv-plugin.php              — Calls register_hooks().
 * @see class-prv-collector-registry.php  — Provides panels.
 * @see class-prv-probe-runner.php        — Invoked by the Run now action.
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
	 * Enqueue Chart.js on the PR Vision page only.
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

		wp_add_inline_style(
			'wp-admin',
			'.prv-meta-bar{display:flex;gap:20px;margin:10px 0 15px;flex-wrap:wrap;}
			 .prv-meta-item{background:#f6f7f7;padding:6px 12px;border-radius:4px;font-size:13px;}
			 .prv-card{background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin-bottom:20px;border-radius:4px;}
			 .prv-card h2{margin-top:0;}
			 .prv-proxy-note{margin:10px 0;}
			 .prv-standings th,.prv-standings td{vertical-align:middle;}
			 canvas#prv-trendline-chart{max-height:300px;}'
		);
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
				'page'        => self::MENU_SLUG,
				'prv_run_done' => 1,
				'prv_probed'  => (int) $counts['probed'],
				'prv_skipped' => (int) ( $counts['skipped_budget'] + $counts['skipped_error'] ),
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

		// "Run now" button form.
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$probed  = isset( $_GET['prv_probed'] ) ? absint( wp_unslash( $_GET['prv_probed'] ) ) : 0;
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
}
