<?php
/**
 * Settings page HTML renderer for PR Vision.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Renders the PR Vision Settings admin page (dark "Assay" theme).
 *
 * Coordinates the page sections: styles, notices, model manager card
 * (via PRV_Model_Manager_Table), secondary config form, provider API key
 * card (via PRV_Key_Manager_Renderer), sticky save-bar, and inline JS.
 * All output is escaped at the point of emission.
 *
 * Who triggers: PRV_Settings_Page::render_page().
 * Dependencies: PRV_Model_Registry, PRV_Config, PRV_Cron, PRV_Run_Lock,
 *               PRV_Config_Version, PRV_Model_Manager_Table,
 *               PRV_Key_Manager_Renderer.
 *
 * @see class-prv-settings-page.php        -- Instantiates and calls render().
 * @see class-prv-model-manager-table.php  -- Renders the model table.
 * @see class-prv-key-manager-renderer.php -- Renders the key card.
 * @package PrVision
 */
class PRV_Settings_Renderer {

	/**
	 * Render the full settings page.
	 *
	 * Side effects: Outputs HTML directly.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->render_styles();
		echo '<div class="prv-settings-wrap">';
		echo '<h1 class="prv-page-title">' . esc_html__( 'PR Vision — Settings', 'pr-vision' ) . '</h1>';
		$this->render_notices();
		echo '<div class="prv-card"><h2>' . esc_html__( 'Model Manager', 'pr-vision' ) . '</h2>';
		( new PRV_Model_Manager_Table() )->render();
		echo '</div>';
		$this->render_config_form();
		( new PRV_Key_Manager_Renderer() )->render();
		$this->render_save_bar();
		$this->render_scripts();
		echo '</div>';
	}

	/**
	 * Render dark CSS variables and component styles.
	 *
	 * @return void
	 */
	private function render_styles(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<style>
:root{--prv-bg:#14181C;--prv-surface:#1C2228;--prv-surface2:#232B33;--prv-surface3:#2A333C;
--prv-border:#333D47;--prv-border-strong:#3A4651;--prv-border-bright:#46535F;
--prv-text:#EEF2F5;--prv-text-sec:#C2CCD6;--prv-text-muted:#9AA7B2;--prv-ink:#0D1116;
--prv-teal:#34C0CA;--prv-teal-hover:#4ACCD5;--prv-lime:#9BE635;
--prv-orange:#FFB36E;--prv-red:#FF6B5E;--prv-red-chip:#FF8A80;
--prv-gray-absent:#8893A0;--prv-chip-text-disabled:#B4BDC7;
--prv-tint-red:rgba(255,107,94,.13);--prv-tint-orange:rgba(255,179,110,.12);
--prv-tint-lime:rgba(155,230,53,.12);--prv-tint-gray:rgba(136,147,160,.12);}
.prv-settings-wrap{background:var(--prv-bg);color:var(--prv-text);padding:24px;font-family:Inter,sans-serif;min-height:600px;}
.prv-page-title{color:var(--prv-text);font-family:Poppins,sans-serif;font-size:24px;font-weight:700;margin:0 0 20px;}
.prv-card{background:var(--prv-surface);border:1px solid var(--prv-border-strong);border-radius:8px;padding:24px;margin-bottom:20px;}
.prv-card h2{color:var(--prv-text);font-size:16px;font-weight:600;margin:0 0 16px;}
.prv-table{width:100%;border-collapse:collapse;font-size:13px;}
.prv-table th{background:var(--prv-surface2);color:var(--prv-text-sec);font-weight:600;text-align:left;padding:8px 12px;border-bottom:1px solid var(--prv-border);}
.prv-table th button{background:none;border:none;color:inherit;cursor:pointer;font:inherit;padding:0;display:inline-flex;align-items:center;gap:4px;}
.prv-table th button:focus-visible{outline:2px solid var(--prv-teal);outline-offset:2px;}
.prv-table td{padding:8px 12px;border-bottom:1px solid var(--prv-border);color:var(--prv-text-sec);vertical-align:middle;}
.prv-row-retired{border-left:3px solid var(--prv-orange);background:repeating-linear-gradient(135deg,var(--prv-surface) 0,var(--prv-surface) 6px,var(--prv-surface2) 6px,var(--prv-surface2) 12px);}
.prv-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:500;}
.prv-badge-healthy{background:var(--prv-tint-lime);color:var(--prv-lime);}
.prv-badge-retired{background:var(--prv-tint-red);color:var(--prv-red-chip);}
.prv-badge-disabled{background:var(--prv-tint-gray);color:var(--prv-chip-text-disabled);}
.prv-badge-unknown{background:var(--prv-tint-gray);color:var(--prv-text-muted);}
.prv-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;font-size:13px;cursor:pointer;border:none;font-weight:500;transition:background .12s;}
.prv-btn:focus-visible{outline:2px solid var(--prv-ink);outline-offset:2px;}
.prv-btn-primary{background:var(--prv-teal);color:var(--prv-ink);}
.prv-btn-primary:hover{background:var(--prv-teal-hover);}
.prv-btn-ghost{background:transparent;color:var(--prv-teal);border:1px solid var(--prv-border-bright);}
.prv-btn-ghost:hover{background:var(--prv-surface3);}
.prv-btn-danger{background:transparent;color:var(--prv-red-chip);border:1px solid var(--prv-border-bright);}
.prv-btn-danger:hover{background:var(--prv-tint-red);}
.prv-btn:disabled,.prv-btn[disabled]{opacity:.5;cursor:not-allowed;}
.prv-input{background:var(--prv-surface2);border:1px solid var(--prv-border-bright);border-radius:6px;color:var(--prv-text);padding:7px 10px;font-size:13px;width:100%;box-sizing:border-box;}
.prv-input:focus{outline:2px solid var(--prv-teal);outline-offset:1px;}
.prv-label{display:block;color:var(--prv-text-sec);font-size:12px;margin-bottom:4px;}
.prv-field{margin-bottom:14px;}
.prv-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.prv-cost-inset{background:var(--prv-surface2);border:1px solid var(--prv-border);border-radius:6px;padding:12px;margin-top:8px;font-size:12px;color:var(--prv-text-sec);}
.prv-cost-inset.over-cap{border-color:var(--prv-orange);background:var(--prv-tint-orange);color:var(--prv-orange);}
.prv-api-status{padding:8px 12px;border-radius:6px;font-size:13px;display:inline-flex;align-items:center;gap:8px;}
.prv-api-ok{background:var(--prv-tint-lime);color:var(--prv-lime);}
.prv-api-fail{background:var(--prv-tint-red);color:var(--prv-red-chip);}
.prv-api-undef{background:var(--prv-tint-gray);color:var(--prv-text-muted);}
.prv-savebar{position:sticky;bottom:0;background:var(--prv-surface2);border-top:1px solid var(--prv-border-strong);padding:12px 24px;display:flex;align-items:center;gap:12px;z-index:50;}
.prv-savebar-warn{color:var(--prv-orange);font-size:12px;flex:1;display:none;}
.prv-savebar-warn.visible{display:block;}
.prv-notice{padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:14px;}
.prv-notice-success{background:var(--prv-tint-lime);color:var(--prv-lime);}
.prv-notice-warning{background:var(--prv-tint-orange);color:var(--prv-orange);}
.prv-section-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--prv-text-muted);margin-bottom:8px;}
@media print{.prv-settings-wrap{background:#fff;color:#111;}.prv-card{background:#fff;border-color:#ccc;}.prv-savebar,.prv-btn-primary,.prv-btn-ghost,.prv-btn-danger{display:none!important;}.prv-table th{background:#f3f3f3;color:#333;}.prv-table td{color:#444;}}
</style>';
	}

	/**
	 * Render POST-redirect notices (saved, run done, model CRUD, key actions, lock).
	 *
	 * @return void
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['prv_saved'] ) ) {
			echo '<div class="prv-notice prv-notice-success">' . esc_html__( 'Settings saved.', 'pr-vision' ) . '</div>';
		}
		if ( ! empty( $_GET['prv_run_done'] ) ) {
			$p   = absint( $_GET['prv_probed'] ?? 0 );
			$s   = absint( $_GET['prv_skipped'] ?? 0 );
			$trn = ! empty( $_GET['prv_truncated'] );
			$msg = sprintf( 'Run complete: %d probed, %d skipped.%s', $p, $s, $trn ? ' Last run was truncated by the monthly budget cap.' : '' );
			echo '<div class="prv-notice prv-notice-success">' . esc_html( $msg ) . '</div>';
		}
		if ( ! empty( $_GET['prv_run_locked'] ) ) {
			echo '<div class="prv-notice prv-notice-warning">' . esc_html__( 'A run is already in progress — try again when it finishes.', 'pr-vision' ) . '</div>';
		}
		if ( ! empty( $_GET['prv_model_added'] ) ) {
			echo '<div class="prv-notice prv-notice-success">' . esc_html__( 'Model added.', 'pr-vision' ) . '</div>';
		}
		if ( ! empty( $_GET['prv_model_updated'] ) ) {
			echo '<div class="prv-notice prv-notice-success">' . esc_html__( 'Model updated.', 'pr-vision' ) . '</div>';
		}
		if ( ! empty( $_GET['prv_model_removed'] ) ) {
			echo '<div class="prv-notice prv-notice-success">' . esc_html__( 'Model removed.', 'pr-vision' ) . '</div>';
		}
		if ( ! empty( $_GET['prv_key_set'] ) ) {
			echo '<div class="prv-notice prv-notice-success">' . esc_html__( 'API key saved.', 'pr-vision' ) . '</div>';
		}
		if ( ! empty( $_GET['prv_key_removed'] ) ) {
			echo '<div class="prv-notice prv-notice-success">' . esc_html__( 'API key removed.', 'pr-vision' ) . '</div>';
		}
		if ( ! empty( $_GET['prv_key_error'] ) ) {
			echo '<div class="prv-notice prv-notice-warning">' . esc_html__( 'Could not save API key — encryption failed. Check server logs.', 'pr-vision' ) . '</div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Render the secondary config form (cadence, budget, peptides, intents).
	 *
	 * @return void
	 */
	private function render_config_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="prv-config-form">';
		echo '<input type="hidden" name="action" value="prv_settings_save">';
		wp_nonce_field( PRV_Settings_Page::NONCE_SAVE, 'prv_nonce' );
		echo '<div class="prv-card"><h2>' . esc_html__( 'Probe Configuration', 'pr-vision' ) . '</h2>';
		$this->render_cadence_field();
		$this->render_budget_field();
		$this->render_projected_cost();
		$this->render_peptides_field();
		$this->render_intents_field();
		echo '</div></form>';
	}

	/**
	 * Render cadence selector with next-run timestamp.
	 *
	 * @return void
	 */
	private function render_cadence_field(): void {
		$cadence  = PRV_Config::get_cadence();
		$next_ts  = PRV_Cron::next_run_timestamp();
		$next_str = $next_ts ? gmdate( 'Y-m-d H:i T', (int) $next_ts ) : 'Not scheduled';
		echo '<div class="prv-field"><label class="prv-label" for="prv_cadence">' . esc_html__( 'Probe cadence', 'pr-vision' ) . '</label>';
		echo '<select class="prv-input" id="prv_cadence" name="prv_cadence" data-scoring-relevant="1" style="max-width:200px">';
		$cadence_options = array(
			'weekly'     => 'Weekly',
			'twicedaily' => 'Twice daily',
			'daily'      => 'Daily',
		);
		foreach ( $cadence_options as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $cadence, $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		echo '<p style="color:var(--prv-text-muted);font-size:12px;margin:4px 0 0">' . esc_html__( 'Next run: ', 'pr-vision' ) . esc_html( $next_str ) . '</p></div>';
	}

	/**
	 * Render monthly budget cap input.
	 *
	 * @return void
	 */
	private function render_budget_field(): void {
		$budget = PRV_Config::get_monthly_budget_usd();
		echo '<div class="prv-field"><label class="prv-label" for="prv_monthly_budget_usd">' . esc_html__( 'Monthly budget cap (USD)', 'pr-vision' ) . '</label>';
		echo '<input class="prv-input" style="max-width:160px;" type="number" min="0.01" step="0.01" id="prv_monthly_budget_usd" name="prv_monthly_budget_usd" value="' . esc_attr( number_format( $budget, 2, '.', '' ) ) . '" data-scoring-relevant="1"></div>';
	}

	/**
	 * Render live projected-cost inset panel.
	 *
	 * @return void
	 */
	private function render_projected_cost(): void {
		$cost = PRV_Config::get_projected_cost();
		$cls  = $cost['over_cap'] ? 'prv-cost-inset over-cap' : 'prv-cost-inset';
		echo '<div id="prv-cost-inset" class="' . esc_attr( $cls ) . '" aria-live="polite">';
		echo '<strong>Projected cost:</strong> ~$' . esc_html( number_format( $cost['per_run_usd'], 4 ) ) . '/run &nbsp;·&nbsp; ';
		echo '~$' . esc_html( number_format( $cost['per_month_usd'], 4 ) ) . '/month &nbsp;·&nbsp; ';
		echo esc_html( (string) $cost['probe_count'] ) . ' probes/run';
		if ( $cost['over_cap'] ) {
			echo ' &nbsp;<strong>&#9888; Over cap — runs will truncate</strong>';
		}
		echo '</div>';
	}

	/**
	 * Render peptide set textarea (JSON).
	 *
	 * @return void
	 */
	private function render_peptides_field(): void {
		$peptides = PRV_Config::get_peptides();
		echo '<div class="prv-field"><label class="prv-label">' . esc_html__( 'Peptide set', 'pr-vision' ) . '</label>';
		echo '<p style="color:var(--prv-text-muted);font-size:12px;margin:0 0 6px">' . esc_html( count( $peptides ) ) . ' peptides (JSON array of {slug, label}).</p>';
		echo '<textarea class="prv-input" name="prv_peptides_json" rows="5" style="font-family:monospace;font-size:12px;" data-scoring-relevant="1">' . esc_textarea( (string) wp_json_encode( $peptides ) ) . '</textarea></div>';
	}

	/**
	 * Render prompt intents textarea.
	 *
	 * @return void
	 */
	private function render_intents_field(): void {
		$intents = PRV_Config::get_prompt_intents();
		echo '<div class="prv-field"><label class="prv-label" for="prv_prompt_intents_text">' . esc_html__( 'Prompt intents (one per line, use {peptide})', 'pr-vision' ) . '</label>';
		echo '<textarea class="prv-input" id="prv_prompt_intents_text" name="prv_prompt_intents_text" rows="4" data-scoring-relevant="1">' . esc_textarea( implode( "\n", $intents ) ) . '</textarea></div>';
	}

	/**
	 * Render the sticky save-bar with scoring-change warning and Run now.
	 *
	 * @return void
	 */
	private function render_save_bar(): void {
		echo '<div class="prv-savebar" id="prv-savebar">';
		echo '<button type="submit" form="prv-config-form" class="prv-btn prv-btn-primary">' . esc_html__( 'Save settings', 'pr-vision' ) . '</button>';
		echo '<span id="prv-savebar-warn" class="prv-savebar-warn" role="alert"></span>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="prv-run-form" style="margin:0">';
		echo '<input type="hidden" name="action" value="prv_settings_run_now">';
		wp_nonce_field( PRV_Settings_Page::NONCE_RUN, 'prv_nonce' );
		$locked = PRV_Run_Lock::is_locked();
		echo '<button type="submit" id="prv-run-btn" class="prv-btn prv-btn-ghost"' . ( $locked ? ' disabled' : '' ) . '>';
		echo $locked ? esc_html__( 'Run in progress…', 'pr-vision' ) : esc_html__( 'Run now', 'pr-vision' );
		echo '</button>';
		echo '</form>';
		$next_ts = PRV_Cron::next_run_timestamp();
		if ( $next_ts ) {
			echo '<span style="color:var(--prv-text-muted);font-size:12px">' . esc_html__( 'Next auto-run: ', 'pr-vision' ) . esc_html( gmdate( 'Y-m-d H:i T', (int) $next_ts ) ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Render inline JS: scoring-change warn, sort headers, Test AJAX, Run-now guard.
	 *
	 * @return void
	 */
	private function render_scripts(): void {
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( PRV_Settings_Page::NONCE_TEST );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>(function(){
var ajaxUrl=' . wp_json_encode( $ajax_url ) . ',testNonce=' . wp_json_encode( $nonce ) . ';
var runFired=false;
var rf=document.getElementById("prv-run-form");
if(rf){rf.addEventListener("submit",function(e){if(runFired){e.preventDefault();return;}runFired=true;var b=document.getElementById("prv-run-btn");if(b){b.disabled=true;b.textContent="Running…";}});}
document.querySelectorAll("[data-scoring-relevant]").forEach(function(f){f.addEventListener("change",function(){var w=document.getElementById("prv-savebar-warn");if(w){w.textContent="Scoring-relevant change — this breaks comparison with prior runs. A new config version will be recorded.";w.classList.add("visible");}var s=document.querySelector(".prv-savebar .prv-btn-primary");if(s){s.textContent="Save anyway";}});});
document.querySelectorAll("th[aria-sort] button").forEach(function(btn){btn.addEventListener("click",function(){var th=btn.closest("th"),table=th.closest("table"),idx=Array.from(th.parentElement.children).indexOf(th);var asc=th.getAttribute("aria-sort")!=="ascending";table.querySelectorAll("th[aria-sort]").forEach(function(h){h.setAttribute("aria-sort","none");});th.setAttribute("aria-sort",asc?"ascending":"descending");var tb=table.querySelector("tbody"),rows=Array.from(tb.querySelectorAll("tr"));rows.sort(function(a,b){var ac=(a.cells[idx]||{}).textContent||"",bc=(b.cells[idx]||{}).textContent||"";return asc?ac.localeCompare(bc):bc.localeCompare(ac);});rows.forEach(function(r){tb.appendChild(r);});});});
document.querySelectorAll(".prv-test-btn").forEach(function(btn){btn.addEventListener("click",function(){var mid=btn.dataset.modelId,chip=document.getElementById("prv-test-chip-"+mid);btn.disabled=true;btn.setAttribute("aria-busy","true");if(chip){chip.textContent="Testing…";chip.className="prv-badge prv-badge-unknown";}fetch(ajaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action:"prv_test_model",model_id:mid,prv_nonce:testNonce})}).then(function(r){return r.json();}).then(function(d){btn.disabled=false;btn.removeAttribute("aria-busy");if(d.success){if(chip){chip.textContent="✓ "+d.data.message;chip.className="prv-badge prv-badge-healthy";}}else{if(chip){chip.textContent="✗ "+(d.data?d.data.message:"Failed");chip.className="prv-badge prv-badge-retired";}}}).catch(function(){btn.disabled=false;btn.removeAttribute("aria-busy");if(chip){chip.textContent="Error";chip.className="prv-badge prv-badge-retired";}});});});
})();</script>';
	}
}
