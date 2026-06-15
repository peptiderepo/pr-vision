<?php
/**
 * Renderer for the Provider API Key manager card on the Settings page.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Renders the write-only Provider API Key manager card.
 *
 * Outputs only the key SOURCE (constant / admin / not set) and the
 * last-run API status. The stored key value is NEVER included in any
 * output: not in HTML, not in JS, not in data attributes.
 *
 * Three states:
 *  - SOURCE_CONSTANT: constant defined → lock the input, show note.
 *  - SOURCE_OPTION:   admin key stored → show "Set via admin" + Replace/Remove.
 *  - SOURCE_NONE:     no key → show "Not set" + Set form.
 *
 * The form submits to admin-post.php (prv_key_set or prv_key_remove),
 * nonce-protected. The password input renders empty on every page load.
 *
 * Who triggers: PRV_Settings_Renderer::render_key_manager_card().
 * Dependencies: PRV_Key_Store, PRV_Settings_Page (nonce constants).
 *
 * @see class-prv-key-store.php         -- Source detection.
 * @see class-prv-settings-renderer.php -- Calls render().
 * @see class-prv-settings-page.php     -- Nonce constants + AJAX action.
 * @package PrVision
 */
class PRV_Key_Manager_Renderer {

	/**
	 * Render the full Provider API Key card.
	 *
	 * Side effects: Outputs HTML directly. Contains no key value.
	 *
	 * @return void
	 */
	public function render(): void {
		$source     = PRV_Key_Store::get_source();
		$is_const   = PRV_Key_Store::SOURCE_CONSTANT === $source;
		$is_set_opt = PRV_Key_Store::SOURCE_OPTION === $source;
		$status     = (string) get_option( 'prv_api_key_status', 'unknown' );
		$last_check = (string) get_option( 'prv_api_key_last_check', '' );

		echo '<div class="prv-card" id="prv-key-card">';
		echo '<h2>' . esc_html__( 'Provider API Key', 'pr-vision' ) . '</h2>';

		$this->render_status_badge( $source, $status, $last_check );
		$this->render_key_form( $is_const, $is_set_opt );
		$this->render_test_button( $source );

		echo '</div>';
	}

	/**
	 * Render the read-only source + health status badge.
	 *
	 * @param string $source     One of PRV_Key_Store::SOURCE_* constants.
	 * @param string $api_status Last stored API status (ok/failed/unknown).
	 * @param string $last_check ISO date of last status update.
	 *
	 * @return void
	 */
	private function render_status_badge( string $source, string $api_status, string $last_check ): void {
		echo '<div class="prv-field">';

		if ( PRV_Key_Store::SOURCE_CONSTANT === $source ) {
			echo '<span class="prv-api-status prv-api-ok" id="prv-key-source-badge">';
			echo '&#9679; ' . esc_html__( 'Set via wp-config (takes precedence)', 'pr-vision' );
			echo '</span>';
		} elseif ( PRV_Key_Store::SOURCE_OPTION === $source ) {
			echo '<span class="prv-api-status prv-api-ok" id="prv-key-source-badge">';
			echo '&#9679; ' . esc_html__( 'Set via admin', 'pr-vision' );
			echo '</span>';
		} else {
			echo '<span class="prv-api-status prv-api-undef" id="prv-key-source-badge">';
			echo '&#9679; ' . esc_html__( 'Not set', 'pr-vision' );
			echo '</span>';
		}

		// Last-run health state.
		if ( '' !== $last_check && PRV_Key_Store::SOURCE_NONE !== $source ) {
			if ( 'failed' === $api_status ) {
				echo '&nbsp;<span class="prv-api-status prv-api-fail">' . esc_html__( 'Last run: failed (key may be invalid or revoked)', 'pr-vision' ) . '</span>';
			} elseif ( 'ok' === $api_status ) {
				echo '&nbsp;<span class="prv-api-status prv-api-ok">' . esc_html__( 'Last run: OK', 'pr-vision' ) . '</span>';
			}
		}

		echo '</div>';
	}

	/**
	 * Render the set/replace/remove key form.
	 *
	 * The password input always renders empty. When constant is set, the
	 * input is disabled with an explanatory note.
	 *
	 * @param bool $is_const   Whether the constant is currently defined.
	 * @param bool $is_set_opt Whether an admin-stored (option) key exists.
	 *
	 * @return void
	 */
	private function render_key_form( bool $is_const, bool $is_set_opt ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="prv-key-set-form" autocomplete="off">';
		echo '<input type="hidden" name="action" value="prv_key_set">';
		wp_nonce_field( PRV_Settings_Page::NONCE_KEY, 'prv_nonce' );

		echo '<div class="prv-field" style="margin-top:12px">';
		echo '<label class="prv-label" for="prv_api_key">';
		echo esc_html(
			$is_set_opt
			? __( 'Replace key (leave empty to keep current)', 'pr-vision' )
			: __( 'Set key', 'pr-vision' )
		);
		echo '</label>';

		// Input always renders empty — never outputs stored key.
		echo '<input type="password" id="prv_api_key" name="prv_api_key" '
			. 'class="prv-input" style="max-width:420px" '
			. 'value="" autocomplete="new-password" '
			. 'placeholder="' . esc_attr( $is_const ? __( 'Managed via wp-config — cannot override', 'pr-vision' ) : __( 'sk-or-…', 'pr-vision' ) ) . '"'
			. ( $is_const ? ' disabled aria-disabled="true"' : '' ) . '>';

		if ( $is_const ) {
			echo '<p style="color:var(--prv-text-muted);font-size:12px;margin:4px 0 0">'
				. esc_html__( 'PRV_OPENROUTER_API_KEY is defined in wp-config.php and takes precedence. Remove it from wp-config to manage the key here.', 'pr-vision' )
				. '</p>';
		}

		echo '</div>';

		if ( ! $is_const ) {
			echo '<button type="submit" class="prv-btn prv-btn-primary" style="margin-top:4px">'
				. esc_html( $is_set_opt ? __( 'Replace key', 'pr-vision' ) : __( 'Set key', 'pr-vision' ) )
				. '</button>';
		}

		echo '</form>';

		// Remove key form (only when admin option is set, not constant).
		if ( $is_set_opt && ! $is_const ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="prv-key-remove-form" style="margin-top:8px">';
			echo '<input type="hidden" name="action" value="prv_key_remove">';
			wp_nonce_field( PRV_Settings_Page::NONCE_KEY, 'prv_nonce' );
			echo '<button type="submit" class="prv-btn prv-btn-danger" '
				. 'onclick="return confirm(\'' . esc_js( __( 'Remove the stored API key? The next probe run will fail unless PRV_OPENROUTER_API_KEY is set in wp-config.php.', 'pr-vision' ) ) . '\')">'
				. esc_html__( 'Remove key', 'pr-vision' )
				. '</button>';
			echo '</form>';
		}
	}

	/**
	 * Render the Test key button and aria-live result area.
	 *
	 * Only shown when a key source is available. The test sends one cheap
	 * probe via AJAX and reports valid/invalid without leaking the key.
	 *
	 * @param string $source Current key source.
	 *
	 * @return void
	 */
	private function render_test_button( string $source ): void {
		if ( PRV_Key_Store::SOURCE_NONE === $source ) {
			return;
		}

		$nonce    = wp_create_nonce( PRV_Settings_Page::NONCE_KEY_TEST );
		$ajax_url = admin_url( 'admin-ajax.php' );

		echo '<div style="margin-top:12px;display:flex;align-items:center;gap:12px">';
		echo '<button type="button" id="prv-test-key-btn" class="prv-btn prv-btn-ghost" '
			. 'data-ajax-url="' . esc_url( $ajax_url ) . '" '
			. 'data-nonce="' . esc_attr( $nonce ) . '">'
			. esc_html__( 'Test key', 'pr-vision' )
			. '</button>';
		echo '<span id="prv-test-key-result" aria-live="polite" role="status" style="font-size:13px"></span>';
		echo '</div>';

		// Inline JS — only a button wirer, no key value in JS.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>(function(){
var btn=document.getElementById("prv-test-key-btn");
if(!btn)return;
btn.addEventListener("click",function(){
  var res=document.getElementById("prv-test-key-result");
  btn.disabled=true;btn.setAttribute("aria-busy","true");
  if(res){res.textContent="Testing…";}
  fetch(btn.dataset.ajaxUrl,{
    method:"POST",
    headers:{"Content-Type":"application/x-www-form-urlencoded"},
    body:new URLSearchParams({action:"prv_test_key",prv_nonce:btn.dataset.nonce})
  }).then(function(r){return r.json();}).then(function(d){
    btn.disabled=false;btn.removeAttribute("aria-busy");
    if(d.success){
      if(res){res.textContent="✓ "+(d.data?d.data.message:"Key valid");res.style.color="var(--prv-lime)";}
    }else{
      if(res){res.textContent="✗ "+(d.data?d.data.message:"Key invalid");res.style.color="var(--prv-red-chip)";}
    }
  }).catch(function(){
    btn.disabled=false;btn.removeAttribute("aria-busy");
    if(res){res.textContent="Request error";res.style.color="var(--prv-red-chip)";}
  });
});
})();</script>';
	}
}
