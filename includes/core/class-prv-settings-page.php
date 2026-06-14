<?php
/**
 * PR Vision admin settings page: model manager + config surface.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * PR Vision Settings admin page.
 *
 * Registers "PR Vision > Settings" sub-menu, dispatches POST actions to
 * PRV_Settings_Controller, handles Run-now and AJAX test-model, and
 * delegates all HTML rendering to PRV_Settings_Renderer.
 *
 * All actions require: current_user_can('manage_options') + valid nonce.
 *
 * Who triggers: PRV_Plugin::init() -- is_admin() guard.
 * Dependencies: PRV_Settings_Controller, PRV_Settings_Renderer,
 *               PRV_Model_Test_Ajax, PRV_Probe_Runner, PRV_Run_Lock.
 *
 * @see class-prv-settings-controller.php -- POST handler implementations.
 * @see class-prv-settings-renderer.php   -- Renders the HTML.
 * @see class-prv-model-test-ajax.php     -- AJAX test handler.
 * @see class-prv-model-registry.php      -- CRUD for prv_models.
 * @see class-prv-config-version.php      -- Config-change versioning.
 * @see class-prv-cron.php                -- Reschedule on cadence change.
 * @package PrVision
 */
class PRV_Settings_Page {

	/** Admin menu slug for the settings page. */
	const MENU_SLUG = 'pr-vision-settings';

	/** Nonce action for settings save. */
	const NONCE_SAVE = 'prv_settings_save';

	/** Nonce action for model CRUD. */
	const NONCE_MODEL = 'prv_model_action';

	/** Nonce action for Run now on settings page. */
	const NONCE_RUN = 'prv_settings_run_now';

	/** Nonce action for Test model AJAX. */
	const NONCE_TEST = 'prv_model_test';

	/**
	 * Register WordPress admin hooks.
	 *
	 * POST handlers for save/model-CRUD are delegated to PRV_Settings_Controller,
	 * which receives a nonce-verification callback bound to this page.
	 *
	 * Side effects: Adds admin_menu, admin_post, and wp_ajax actions.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$ctrl = new PRV_Settings_Controller(
			function ( string $action ): void {
				$this->require_admin_nonce( $action );
			}
		);

		add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
		add_action( 'admin_post_prv_settings_save', array( $ctrl, 'handle_save' ) );
		add_action( 'admin_post_prv_model_add', array( $ctrl, 'handle_model_add' ) );
		add_action( 'admin_post_prv_model_update', array( $ctrl, 'handle_model_update' ) );
		add_action( 'admin_post_prv_model_remove', array( $ctrl, 'handle_model_remove' ) );
		add_action( 'admin_post_prv_settings_run_now', array( $this, 'handle_run_now' ) );
		add_action( 'wp_ajax_prv_test_model', array( $this, 'handle_test_model' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the Settings sub-menu under PR Vision.
	 *
	 * Side effects: Adds a WP admin sub-menu entry.
	 *
	 * @return void
	 */
	public function add_settings_menu(): void {
		add_submenu_page(
			'pr-vision',
			__( 'PR Vision Settings', 'pr-vision' ),
			__( 'Settings', 'pr-vision' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue settings page Chart.js.
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
	}

	/**
	 * Handle Run now POST from the settings page.
	 *
	 * Refuses if a run is already in progress.
	 *
	 * Side effects: May trigger probe run; redirects.
	 *
	 * @return void
	 */
	public function handle_run_now(): void {
		$this->require_admin_nonce( self::NONCE_RUN );
		if ( PRV_Run_Lock::is_locked() ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => self::MENU_SLUG,
						'prv_run_locked' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		$runner = new PRV_Probe_Runner();
		$counts = $runner->run();
		$args   = array(
			'page'          => self::MENU_SLUG,
			'prv_run_done'  => 1,
			'prv_probed'    => (int) $counts['probed'],
			'prv_skipped'   => (int) ( $counts['skipped_budget'] + max( 0, $counts['skipped_error'] ) ),
			'prv_truncated' => $counts['truncated'] ? 1 : 0,
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Dispatch the AJAX test-model request to PRV_Model_Test_Ajax.
	 *
	 * Side effects: Exits via PRV_Model_Test_Ajax::handle().
	 *
	 * @return void
	 */
	public function handle_test_model(): void {
		( new PRV_Model_Test_Ajax() )->handle();
	}

	/**
	 * Render the settings page.
	 *
	 * Side effects: Outputs HTML via PRV_Settings_Renderer.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'pr-vision' ) );
		}
		( new PRV_Settings_Renderer() )->render();
	}

	/**
	 * Verify admin capability and nonce; wp_die on failure.
	 *
	 * Called directly for Run-now, and passed as a callback to
	 * PRV_Settings_Controller for the four POST handlers.
	 *
	 * @param string $action Nonce action.
	 *
	 * @return void
	 */
	public function require_admin_nonce( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'pr-vision' ), 403 );
		}
		$nonce = isset( $_POST['prv_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['prv_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'pr-vision' ), 403 );
		}
	}
}
