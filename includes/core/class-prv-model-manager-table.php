<?php
/**
 * Model manager table renderer for PR Vision settings page.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Renders the Model Manager table and Add-model form.
 *
 * The table shows all models (provider, slug, enabled toggle, run-health badge,
 * Test button, edit and remove controls). Retired rows are styled with full AA
 * contrast (no opacity collapse), a left-edge, diagonal hatch, strikethrough
 * name, and a disabled toggle. The Test button is a point-in-time check; run-
 * health is the passive per-run detector.
 *
 * Who triggers: PRV_Settings_Renderer::render_model_manager().
 * Dependencies: PRV_Model_Registry, PRV_Settings_Page (nonce constants).
 *
 * @see class-prv-settings-renderer.php -- Instantiates and calls render().
 * @see class-prv-model-registry.php    -- Source of model data.
 * @package PrVision
 */
class PRV_Model_Manager_Table {

	/**
	 * Render the model table + add-model form.
	 *
	 * Side effects: Outputs HTML directly.
	 *
	 * @return void
	 */
	public function render(): void {
		$models = PRV_Model_Registry::get_all();

		echo '<table class="prv-table" id="prv-models-table">';
		echo '<thead><tr>';
		echo '<th aria-sort="none"><button>' . esc_html__( 'Provider', 'pr-vision' ) . ' <span aria-hidden="true">⇅</span></button></th>';
		echo '<th aria-sort="none"><button>' . esc_html__( 'Model slug', 'pr-vision' ) . ' <span aria-hidden="true">⇅</span></button></th>';
		echo '<th>' . esc_html__( 'Enabled', 'pr-vision' ) . '</th>';
		echo '<th aria-sort="none"><button>' . esc_html__( 'Run health', 'pr-vision' ) . ' <span aria-hidden="true">⇅</span></button></th>';
		echo '<th>' . esc_html__( 'Test (point-in-time)', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'pr-vision' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $models as $m ) {
			$this->render_model_row( $m );
		}

		echo '</tbody></table>';
		echo '<div style="margin-top:16px">';
		$this->render_add_model_form();
		echo '</div>';
	}

	/**
	 * Render a single model table row.
	 *
	 * @param array<string, mixed> $m Model object from PRV_Model_Registry.
	 *
	 * @return void
	 */
	private function render_model_row( array $m ): void {
		$id      = esc_attr( (string) ( $m['id'] ?? '' ) );
		$slug    = esc_html( (string) ( $m['slug'] ?? '' ) );
		$prov    = esc_html( (string) ( $m['provider'] ?? '' ) );
		$enabled = ! empty( $m['enabled'] );
		$health  = (string) ( $m['health_status'] ?? 'unknown' );
		$retired = 'retired' === $health;
		$note    = esc_html( (string) ( $m['note'] ?? '' ) );

		$row_class = $retired ? 'prv-row-retired' : '';
		echo '<tr class="' . esc_attr( $row_class ) . '">';

		// Provider.
		echo '<td>' . esc_html( $prov ) . '</td>';

		// Slug (monospace).
		echo '<td><code style="background:var(--prv-surface3);padding:1px 5px;border-radius:3px;font-size:12px;' . ( $retired ? 'text-decoration:line-through;' : '' ) . '">' . esc_html( $slug ) . '</code>';
		if ( $note ) {
			echo '<br><span style="color:var(--prv-text-muted);font-size:11px">' . esc_html( $note ) . '</span>';
		}
		echo '</td>';

		// Enabled toggle.
		echo '<td>';
		$form_name = 'prv_enable_' . $id;
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="prv_model_update">';
		echo '<input type="hidden" name="prv_model_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="prv_model_slug" value="' . esc_attr( (string) ( $m['slug'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="prv_model_note" value="' . esc_attr( (string) ( $m['note'] ?? '' ) ) . '">';
		wp_nonce_field( PRV_Settings_Page::NONCE_MODEL, 'prv_nonce' );
		// Disabled toggle for retired models.
		$toggle_attrs = $retired ? ' disabled aria-disabled="true"' : '';
		$checked_attr = $enabled ? ' checked' : '';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $checked_attr and $toggle_attrs are hard-coded attribute strings; $enabled is a boolean cast.
		echo '<input type="checkbox" name="prv_model_enabled" onchange="this.form.submit()" ' . $checked_attr . $toggle_attrs . ' title="' . ( $enabled ? 'Disable' : 'Enable' ) . '">';
		echo '</form>';
		if ( $retired ) {
			echo '<span class="prv-badge prv-badge-retired" style="margin-left:6px">' . esc_html__( 'Retired', 'pr-vision' ) . '</span>';
		}
		echo '</td>';

		// Run health.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_health_badge() returns pre-escaped HTML markup.
		echo '<td>' . $this->render_health_badge( $m ) . '</td>';

		// Test button + chip.
		$chip_id = 'prv-test-chip-' . $id;
		echo '<td>';
		echo '<button type="button" class="prv-btn prv-btn-ghost prv-test-btn" data-model-id="' . esc_attr( $id ) . '">';
		echo esc_html__( 'Test', 'pr-vision' );
		echo '</button>';
		echo ' <span id="' . esc_attr( $chip_id ) . '" class="prv-badge prv-badge-unknown" role="status" aria-live="polite"></span>';
		echo '</td>';

		// Actions: remove.
		echo '<td>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'Remove this model?\');">';
		echo '<input type="hidden" name="action" value="prv_model_remove">';
		echo '<input type="hidden" name="prv_model_id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( PRV_Settings_Page::NONCE_MODEL, 'prv_nonce' );
		echo '<button type="submit" class="prv-btn prv-btn-danger">' . esc_html__( 'Remove', 'pr-vision' ) . '</button>';
		echo '</form>';
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * Render a health badge for a model.
	 *
	 * @param array<string, mixed> $m Model object.
	 *
	 * @return string HTML badge (pre-escaped).
	 */
	private function render_health_badge( array $m ): string {
		$status = (string) ( $m['health_status'] ?? 'unknown' );
		$probed = (int) ( $m['health_probed'] ?? 0 );
		$errors = (int) ( $m['health_errors'] ?? 0 );

		switch ( $status ) {
			case 'healthy':
				return '<span class="prv-badge prv-badge-healthy">&#9679; Healthy (' . esc_html( (string) $probed ) . ' rows)</span>';
			case 'retired':
				return '<span class="prv-badge prv-badge-retired">&#9679; Retired? (0 rows · ' . esc_html( (string) $errors ) . ' errors)</span>';
			case 'disabled':
				return '<span class="prv-badge prv-badge-disabled">&#9679; Off · retired</span>';
			default:
				return '<span class="prv-badge prv-badge-unknown">&#9679; Unknown</span>';
		}
	}

	/**
	 * Render the Add model form below the table.
	 *
	 * @return void
	 */
	private function render_add_model_form(): void {
		echo '<details style="border:1px solid var(--prv-border);border-radius:6px;padding:12px;background:var(--prv-surface2)">';
		echo '<summary style="cursor:pointer;color:var(--prv-teal);font-size:13px;font-weight:500">+ ' . esc_html__( 'Add model', 'pr-vision' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px">';
		echo '<input type="hidden" name="action" value="prv_model_add">';
		wp_nonce_field( PRV_Settings_Page::NONCE_MODEL, 'prv_nonce' );
		echo '<div>';
		echo '<label class="prv-label" for="prv_new_model_slug">' . esc_html__( 'Model slug', 'pr-vision' ) . '</label>';
		echo '<input class="prv-input" type="text" id="prv_new_model_slug" name="prv_model_slug" placeholder="openai/gpt-4o-search-preview" required>';
		echo '</div>';
		echo '<div>';
		echo '<label class="prv-label" for="prv_new_model_provider">' . esc_html__( 'Provider', 'pr-vision' ) . '</label>';
		echo '<select class="prv-input" id="prv_new_model_provider" name="prv_model_provider">';
		echo '<option value="openrouter">openrouter</option>';
		echo '<option value="perplexity">perplexity</option>';
		echo '</select>';
		echo '</div>';
		echo '<div style="grid-column:1/-1">';
		echo '<label class="prv-label" for="prv_new_model_note">' . esc_html__( 'Note (optional)', 'pr-vision' ) . '</label>';
		echo '<input class="prv-input" type="text" id="prv_new_model_note" name="prv_model_note" placeholder="Why this model?">';
		echo '</div>';
		echo '<div style="grid-column:1/-1;display:flex;align-items:center;gap:10px">';
		echo '<label><input type="checkbox" name="prv_model_enabled" value="1" checked> ' . esc_html__( 'Enabled', 'pr-vision' ) . '</label>';
		echo '<button type="submit" class="prv-btn prv-btn-primary">' . esc_html__( 'Add model', 'pr-vision' ) . '</button>';
		echo '</div>';
		echo '</form>';
		echo '</details>';
	}
}
