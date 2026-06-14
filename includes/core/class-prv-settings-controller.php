<?php
/**
 * PR Vision settings POST-action controller.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Handles the four settings POST actions: save config, and model CRUD.
 *
 * Extracted from PRV_Settings_Page to keep each file under the 300-line
 * limit. PRV_Settings_Page registers all hooks and delegates the four
 * admin_post_* handlers here.
 *
 * All actions require: current_user_can('manage_options') + valid nonce
 * (enforced by PRV_Settings_Page::require_admin_nonce(), passed in via
 * the $nonce_cb callable).
 *
 * Who triggers: PRV_Settings_Page -- via delegation in register_hooks().
 * Dependencies: PRV_Config, PRV_Config_Version, PRV_Cron,
 *               PRV_Model_Registry.
 *
 * @see class-prv-settings-page.php -- Page registration, dispatch, Run-now, AJAX.
 * @see class-prv-model-registry.php -- CRUD for prv_models.
 * @see class-prv-config-version.php -- Config-change versioning.
 * @see class-prv-cron.php          -- Reschedule on cadence change.
 * @package PrVision
 */
class PRV_Settings_Controller {

	/** Admin menu slug (shared constant with PRV_Settings_Page). */
	const MENU_SLUG = 'pr-vision-settings';

	/**
	 * Nonce-verification callback supplied by PRV_Settings_Page.
	 *
	 * @var callable(): void
	 */
	private $nonce_cb;

	/**
	 * Constructor.
	 *
	 * @param callable $nonce_cb Called with a nonce-action string; wp_die on failure.
	 */
	public function __construct( callable $nonce_cb ) {
		$this->nonce_cb = $nonce_cb;
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
		( $this->nonce_cb )( PRV_Settings_Page::NONCE_SAVE );
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
		( $this->nonce_cb )( PRV_Settings_Page::NONCE_MODEL );
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
		( $this->nonce_cb )( PRV_Settings_Page::NONCE_MODEL );
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
		( $this->nonce_cb )( PRV_Settings_Page::NONCE_MODEL );
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
}
