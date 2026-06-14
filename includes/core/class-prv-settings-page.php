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
 * Registers "PR Vision > Settings" sub-menu, handles POST actions (save config,
 * model CRUD, Run now) and the test-model AJAX hook. Rendering is delegated
 * to PRV_Settings_Renderer; test AJAX to PRV_Model_Test_Ajax.
 *
 * All actions require: current_user_can('manage_options') + valid nonce.
 *
 * Who triggers: PRV_Plugin::init() -- is_admin() guard.
 * Dependencies: PRV_Model_Registry, PRV_Config, PRV_Cron, PRV_Run_Lock,
 *               PRV_Config_Version, PRV_Settings_Renderer, PRV_Model_Test_Ajax.
 *
 * @see class-prv-settings-renderer.php -- Renders the HTML.
 * @see class-prv-model-test-ajax.php   -- AJAX test handler.
 * @see class-prv-model-registry.php    -- CRUD for prv_models.
 * @see class-prv-config-version.php    -- Config-change versioning.
 * @see class-prv-cron.php              -- Reschedule on cadence change.
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
	 * Side effects: Adds admin_menu, admin_post, and wp_ajax actions.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
		add_action( 'admin_post_prv_settings_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_prv_model_add', array( $this, 'handle_model_add' ) );
		add_action( 'admin_post_prv_model_update', array( $this, 'handle_model_update' ) );
		add_action( 'admin_post_prv_model_remove', array( $this, 'handle_model_remove' ) );
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
	 * Handle the settings save POST action.
	 *
	 * Validates, sanitizes, saves peptides/intents/cadence/budget. Bumps
	 * config-version if scoring-relevant fields changed. Reschedules cron
	 * if cadence changed.
	 *
	 * Side effects: Updates wp_options, may reschedule cron, redirects.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->require_admin_nonce( self::NONCE_SAVE );
		$old_cadence = PRV_Config::get_cadence();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via require_admin_nonce().
		$budget  = isset( $_POST['prv_monthly_budget_usd'] )
			? max( 0.01, (float) sanitize_text_field( wp_unslash( (string) $_POST['prv_monthly_budget_usd'] ) ) )
			: PRV_DEFAULT_MONTHLY_BUDGET_USD;
		update_option( 'prv_monthly_budget_usd', $budget );

		$cadence_raw = isset( $_POST['prv_cadence'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['prv_cadence'] ) )
			: 'weekly';
		$cadence = in_array( $cadence_raw, array( 'weekly', 'daily', 'twicedaily' ), true ) ? $cadence_raw : 'weekly';
		update_option( PRV_Config::CADENCE_KEY, $cadence );

		if ( ! empty( $_POST['prv_peptides_json'] ) ) {
			$raw_json = sanitize_textarea_field( wp_unslash( (string) $_POST['prv_peptides_json'] ) );
			$decoded  = json_decode( $raw_json, true );
			if ( is_array( $decoded ) ) {
				$clean = array();
				foreach ( $decoded as $p ) {
					if ( isset( $p['slug'], $p['label'] ) ) {
						$clean[] = array(
							'slug'  => sanitize_text_field( (string) $p['slug'] ),
							'label' => sanitize_text_field( (string) $p['label'] ),
						);
					}
				}
				update_option( 'prv_peptides', $clean );
			}
		}

		if ( isset( $_POST['prv_prompt_intents_text'] ) ) {
			$raw_intents  = sanitize_textarea_field( wp_unslash( (string) $_POST['prv_prompt_intents_text'] ) );
			$intent_lines = array_filter( array_map( 'trim', explode( "\n", $raw_intents ) ) );
			update_option( 'prv_prompt_intents', array_values( $intent_lines ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		PRV_Config_Version::bump_version_if_changed();

		if ( $cadence !== $old_cadence ) {
			PRV_Cron::reschedule( $cadence );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::MENU_SLUG,
					'prv_saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle Add model POST.
	 *
	 * Side effects: Adds to prv_models; may bump config-version; redirects.
	 *
	 * @return void
	 */
	public function handle_model_add(): void {
		$this->require_admin_nonce( self::NONCE_MODEL );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via require_admin_nonce().
		$slug     = sanitize_text_field( wp_unslash( (string) ( $_POST['prv_model_slug'] ?? '' ) ) );
		$provider = sanitize_text_field( wp_unslash( (string) ( $_POST['prv_model_provider'] ?? 'openrouter' ) ) );
		$note     = sanitize_text_field( wp_unslash( (string) ( $_POST['prv_model_note'] ?? '' ) ) );
		$enabled  = ! empty( $_POST['prv_model_enabled'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' !== $slug ) {
			PRV_Model_Registry::add( $slug, $provider, $enabled, $note );
			PRV_Config_Version::bump_version_if_changed();
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::MENU_SLUG,
					'prv_model_added' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle Update model POST.
	 *
	 * Side effects: Updates prv_models; may bump config-version; redirects.
	 *
	 * @return void
	 */
	public function handle_model_update(): void {
		$this->require_admin_nonce( self::NONCE_MODEL );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via require_admin_nonce().
		$id      = sanitize_text_field( wp_unslash( (string) ( $_POST['prv_model_id'] ?? '' ) ) );
		$slug    = sanitize_text_field( wp_unslash( (string) ( $_POST['prv_model_slug'] ?? '' ) ) );
		$enabled = ! empty( $_POST['prv_model_enabled'] );
		$note    = sanitize_text_field( wp_unslash( (string) ( $_POST['prv_model_note'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' !== $id && '' !== $slug ) {
			PRV_Model_Registry::update(
				$id,
				array(
					'slug'    => $slug,
					'enabled' => $enabled,
					'note'    => $note,
				)
			);
			PRV_Config_Version::bump_version_if_changed();
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::MENU_SLUG,
					'prv_model_updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle Remove model POST.
	 *
	 * Side effects: Removes from prv_models; may bump config-version; redirects.
	 *
	 * @return void
	 */
	public function handle_model_remove(): void {
		$this->require_admin_nonce( self::NONCE_MODEL );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via require_admin_nonce().
		$id = sanitize_text_field( wp_unslash( (string) ( $_POST['prv_model_id'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' !== $id ) {
			PRV_Model_Registry::remove( $id );
			PRV_Config_Version::bump_version_if_changed();
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::MENU_SLUG,
					'prv_model_removed' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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
	 * @param string $action Nonce action.
	 *
	 * @return void
	 */
	private function require_admin_nonce( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'pr-vision' ), 403 );
		}
		$nonce = isset( $_POST['prv_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['prv_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'pr-vision' ), 403 );
		}
	}
}
