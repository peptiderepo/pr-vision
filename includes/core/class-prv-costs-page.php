<?php
/**
 * PR Vision Costs admin sub-page (v0.3.0).
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Costs sub-page: MTD spend, cap progress, model breakdown, drill-down.
 *
 * Registers "PR Vision > Costs" sub-menu and renders the Costs page via
 * PRV_Costs_Renderer. All data is read from PRV_Cost_Rollup_Query.
 *
 * Who triggers: PRV_Plugin::init() — is_admin() guard.
 * Dependencies: PRV_Cost_Rollup_Query, PRV_Costs_Renderer, PRV_Config.
 *
 * @see class-prv-costs-renderer.php     — HTML rendering.
 * @see class-prv-cost-rollup-query.php  — Query layer.
 * @see class-prv-admin-page.php         — Dashboard (parent menu slug).
 * @package PrVision
 */
class PRV_Costs_Page {

	/**
	 * Admin menu slug.
	 */
	const MENU_SLUG = 'pr-vision-costs';

	/**
	 * Register WordPress admin hooks.
	 *
	 * Side effects: Adds admin_menu action.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
	}

	/**
	 * Register the Costs sub-menu under PR Vision.
	 *
	 * Side effects: Adds a WP admin sub-menu entry.
	 *
	 * @return void
	 */
	public function add_submenu(): void {
		add_submenu_page(
			PRV_Admin_Page::MENU_SLUG,
			__( 'PR Vision — Costs', 'pr-vision' ),
			__( 'Costs', 'pr-vision' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the full Costs page.
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'pr-vision' ) );
		}

		$query   = new PRV_Cost_Rollup_Query();
		$summary = $query->get_mtd_summary();
		$models  = $query->get_model_breakdown();

		// Drill-down parameters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$level = isset( $_GET['prv_level'] ) ? sanitize_text_field( wp_unslash( $_GET['prv_level'] ) ) : 'run';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset = isset( $_GET['prv_offset'] ) ? absint( wp_unslash( $_GET['prv_offset'] ) ) : 0;
		$drill  = $query->get_drill_down( $level, $offset );

		$cap       = PRV_Config::get_monthly_budget_usd();
		$mtd_cost  = $summary['total_cost'];
		$projected = $query->project_month_end( $mtd_cost );

		$renderer = new PRV_Costs_Renderer();
		$renderer->render(
			array(
				'mtd_cost'    => $mtd_cost,
				'total_calls' => $summary['total_calls'],
				'avg_cost'    => $summary['avg_cost'],
				'cap'         => $cap,
				'projected'   => $projected,
				'models'      => $models,
				'drill'       => $drill,
				'level'       => $level,
				'offset'      => $offset,
			)
		);
	}
}
