<?php
/**
 * Renders the per-call detail drawer (v0.3.0).
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Renders the per-call detail drawer — a non-modal complementary panel.
 *
 * Security contract (P0):
 *   The drawer only renders: prompt_text, response_text, and call metadata.
 *   There is NO section for headers, Authorization, raw HTTP request, or any
 *   field not in the allowlist. This is a structural guarantee — the data
 *   never reaches this renderer.
 *
 * Drawer is role="complementary" (non-modal). Focus goes to close button on
 * open; Escape closes + returns focus to the triggering row. No tab-trap.
 * All states: Normal, Pruned, Legacy/not-captured, Failed, Loading, Empty.
 *
 * Who triggers: PRV_Call_Log_Table_Renderer::render_container(); JS opens
 *               it via fetch on row click.
 * Dependencies: None (pure HTML/JS renderer).
 *
 * @see class-prv-call-log-table-renderer.php — Emits the container in page.
 * @see class-prv-call-log-query.php          — Supplies I/O + metadata data.
 * @package PrVision
 */
class PRV_Call_Drawer_Renderer {

	/**
	 * Emit the drawer container and inline JS for open/close/fetch.
	 *
	 * This is the static shell; content is populated via AJAX/JS.
	 * Side effects: Outputs HTML + inline script.
	 *
	 * @return void
	 */
	public function render_container(): void {
		echo '<div id="prv-drawer" class="prv-drawer" role="complementary" aria-label="' . esc_attr__( 'Call details', 'pr-vision' ) . '" hidden>';
		echo '<div class="prv-drawer-header">';
		echo '<button id="prv-drawer-close" class="prv-drawer-close" aria-label="' . esc_attr__( 'Close call details', 'pr-vision' ) . '">&#x2715; ' . esc_html__( 'Close', 'pr-vision' ) . '</button>';
		echo '<span id="prv-drawer-title" class="prv-drawer-title"></span>';
		echo '</div>';
		echo '<div id="prv-drawer-body" class="prv-drawer-body" aria-live="polite" aria-busy="false">';
		echo '<div class="prv-drawer-skeleton" aria-hidden="true">';
		echo '<div class="prv-skel-line prv-skel-line--wide"></div>';
		echo '<div class="prv-skel-line prv-skel-line--med"></div>';
		echo '<div class="prv-skel-line prv-skel-line--sm"></div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		// Overlay — pointer-events:none so table stays interactive.
		echo '<div id="prv-drawer-overlay" class="prv-drawer-overlay" aria-hidden="true" style="pointer-events:none"></div>';

		$this->render_inline_script();
	}

	/**
	 * Render a populated drawer for a specific call (server-side variant).
	 *
	 * Used when building drawer HTML server-side (e.g., for AJAX endpoint).
	 * Security: only prompt_text and response_text shown — never headers.
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param array<string, mixed>       $meta Call metadata row.
	 * @param array<string, string>|null $io   I/O record or null (pruned/missing).
	 *
	 * @return void
	 */
	public function render_content( array $meta, ?array $io ): void {
		$id         = (int) $meta['id'];
		$captured   = (string) $meta['captured_at'];
		$peptide    = (string) $meta['peptide_slug'];
		$model      = (string) $meta['model'];
		$intent     = (string) $meta['intent_label'];
		$http_status = (int) ( $meta['http_status'] ?? 200 );
		$is_error   = $http_status >= 400;
		$cited      = isset( $meta['cited'] ) ? $meta['cited'] : null;
		$tok_in     = isset( $meta['tokens_in'] ) ? (int) $meta['tokens_in'] : null;
		$tok_out    = isset( $meta['tokens_out'] ) ? (int) $meta['tokens_out'] : null;
		$latency_ms = isset( $meta['latency_ms'] ) ? (int) $meta['latency_ms'] : null;
		$cost_usd   = (float) ( $meta['cost_usd'] ?? 0.0 );
		$run_id     = (string) ( $meta['run_id'] ?? '' );
		$config_ver  = isset( $meta['config_version'] ) ? (int) $meta['config_version'] : null;
		$io_captured = isset( $meta['io_captured'] ) ? (int) $meta['io_captured'] : 0;

		// Determine drawer state using the explicit io_captured flag.
		// io_captured = 1 AND io row present  → Normal.
		// io_captured = 1 AND io row absent   → Pruned (aged out by retention cron).
		// io_captured = 0                     → Legacy / not captured (pre-feature row).
		$has_io      = ( null !== $io );
		$vis_row_null = empty( $meta['visibility_row'] );
		if ( ! $is_error ) {
			$is_legacy = ( 0 === $io_captured );
			$is_pruned = ( 1 === $io_captured && ! $has_io );
		} else {
			$is_legacy = false;
			$is_pruned = false;
		}

		// Header.
		echo '<div class="prv-drawer-meta-head">';
		echo '<span class="prv-drawer-id">' . esc_html( sprintf( /* translators: %d: call ID */ __( 'Call #%d', 'pr-vision' ), $id ) ) . '</span>';
		echo '<span class="prv-drawer-ts">' . esc_html( $captured ) . ' UTC</span>';
		echo '</div>';

		echo '<div class="prv-drawer-topline">';
		echo '<span class="prv-drawer-peptide">' . esc_html( $peptide ) . '</span>';
		if ( '' !== $intent ) {
			echo ' &middot; <span class="prv-drawer-intent">' . esc_html( $intent ) . '</span>';
		}
		echo '</div>';

		// Verdict chip.
		if ( $is_error ) {
			echo '<span class="prv-status prv-status--error">&#x2715; Error</span>';
		} elseif ( null !== $cited ) {
			if ( (bool) $cited ) {
				echo '<span class="prv-status prv-status--cited">&#x2713; Cited</span>';
			} else {
				echo '<span class="prv-status prv-status--not-yet">&#x2013; Not yet</span>';
			}
		}

		// Metadata section.
		echo '<dl class="prv-drawer-dl">';
		echo '<dt>' . esc_html__( 'Model', 'pr-vision' ) . '</dt><dd><code>' . esc_html( $model ) . '</code></dd>';

		$tok_str = ( null !== $tok_in && null !== $tok_out )
			? sprintf( '↑ %s in / ↓ %s out', number_format( $tok_in ), number_format( $tok_out ) )
			: '—';
		echo '<dt>' . esc_html__( 'Tokens', 'pr-vision' ) . '</dt><dd>' . esc_html( $tok_str ) . '</dd>';

		echo '<dt>' . esc_html__( 'Latency', 'pr-vision' ) . '</dt><dd>';
		echo null !== $latency_ms ? esc_html( number_format( $latency_ms ) ) . 'ms' : '—';
		echo '</dd>';

		echo '<dt>' . esc_html__( 'Cost', 'pr-vision' ) . '</dt><dd>$' . esc_html( number_format( $cost_usd, 6 ) ) . '</dd>';

		if ( '' !== $run_id ) {
			echo '<dt>' . esc_html__( 'Run ID', 'pr-vision' ) . '</dt><dd><code class="prv-mono-sm">' . esc_html( substr( $run_id, 0, 8 ) ) . '…</code></dd>';
		}

		if ( null !== $config_ver ) {
			echo '<dt>' . esc_html__( 'Config', 'pr-vision' ) . '</dt><dd>v' . esc_html( (string) $config_ver ) . '</dd>';
		}

		if ( $is_error ) {
			echo '<dt>' . esc_html__( 'HTTP Status', 'pr-vision' ) . '</dt><dd>' . esc_html( (string) $http_status ) . '</dd>';
		}

		echo '</dl>';

		// Prompt section.
		echo '<div class="prv-drawer-section">';
		echo '<div class="prv-drawer-section-head">';
		echo '<span class="prv-drawer-section-label">' . esc_html__( 'PROMPT SENT', 'pr-vision' ) . '</span>';
		if ( $has_io && isset( $io['prompt_text'] ) && '' !== $io['prompt_text'] ) {
			echo '<button class="prv-copy-btn" data-copy-target="prv-prompt-text" aria-label="' . esc_attr__( 'Copy prompt text to clipboard', 'pr-vision' ) . '">' . esc_html__( 'Copy prompt text', 'pr-vision' ) . '</button>';
		}
		echo '</div>';

		if ( $is_legacy ) {
			$this->render_not_captured_notice( $captured );
		} elseif ( $is_pruned ) {
			$this->render_pruned_notice( $captured );
		} elseif ( $has_io && isset( $io['prompt_text'] ) ) {
			echo '<pre id="prv-prompt-text" class="prv-code-block">' . esc_html( $io['prompt_text'] ) . '</pre>';
		} else {
			echo '<p class="prv-drawer-na">—</p>';
		}
		echo '</div>';

		// Response section.
		echo '<div class="prv-drawer-section">';
		echo '<div class="prv-drawer-section-head">';
		echo '<span class="prv-drawer-section-label">' . esc_html__( 'RAW RESPONSE', 'pr-vision' ) . '</span>';
		if ( $has_io && isset( $io['response_text'] ) && '' !== $io['response_text'] ) {
			echo '<button class="prv-copy-btn" data-copy-target="prv-response-text" aria-label="' . esc_attr__( 'Copy response text to clipboard', 'pr-vision' ) . '">' . esc_html__( 'Copy response text', 'pr-vision' ) . '</button>';
		}
		echo '</div>';

		if ( $is_error ) {
			echo '<div class="prv-error-block" role="alert">';
			echo '<span class="prv-error-icon" aria-hidden="true">&#x2715;</span>';
			echo '<div>';
			echo '<strong>' . esc_html__( 'Call failed — no response captured', 'pr-vision' ) . '</strong><br>';
			echo esc_html(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Provider returned HTTP %d. The probe was not counted against the budget.', 'pr-vision' ),
					$http_status
				)
			);
			echo '</div></div>';
		} elseif ( ! $is_legacy && ! $is_pruned && $has_io && isset( $io['response_text'] ) && '' !== $io['response_text'] ) {
			echo '<pre id="prv-response-text" class="prv-code-block">' . esc_html( $io['response_text'] ) . '</pre>';
		} elseif ( ! $is_error && ! $is_legacy && ! $is_pruned ) {
			echo '<p class="prv-drawer-na">—</p>';
		}
		echo '</div>';
	}

	/**
	 * Render the "pruned" I/O notice (aged out, not an error).
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param string $captured_at Timestamp when the call was captured.
	 *
	 * @return void
	 */
	private function render_pruned_notice( string $captured_at ): void {
		echo '<div class="prv-drawer-notice prv-drawer-notice--muted">';
		echo '<span class="prv-notice-icon" aria-hidden="true">&#x231B;</span>';
		echo '<div>';
		echo '<strong>' . esc_html__( 'Prompt and response pruned', 'pr-vision' ) . '</strong><br>';
		echo esc_html(
			sprintf(
				/* translators: 1: retention days, 2: captured date */
				__( 'Raw I/O is kept for %1$d days, then auto-deleted. This call was recorded on %2$s UTC.', 'pr-vision' ),
				PRV_Config::get_io_retention_days(),
				$captured_at
			)
		);
		echo '</div></div>';
	}

	/**
	 * Render the "not captured" notice (pre-v0.3 row, never stored).
	 *
	 * Distinct from the pruned state — different copy prevents confusion.
	 * Side effects: Outputs HTML.
	 *
	 * @param string $captured_at Timestamp when the call was captured.
	 *
	 * @return void
	 */
	private function render_not_captured_notice( string $captured_at ): void {
		echo '<div class="prv-drawer-notice prv-drawer-notice--muted">';
		echo '<span class="prv-notice-icon" aria-hidden="true">&#x2139;</span>';
		echo '<div>';
		echo '<strong>' . esc_html__( 'Not captured for this call', 'pr-vision' ) . '</strong><br>';
		echo esc_html__( 'This call was recorded before the call-inspector feature was added. Prompt and response were never stored — this is not a retention prune.', 'pr-vision' );
		echo '</div></div>';
	}

	/**
	 * Emit the inline JavaScript for drawer open/close/copy behaviour.
	 *
	 * Security: data is fetched from a server endpoint that enforces the
	 * capture allowlist — no keys or headers can appear in drawer content.
	 * Side effects: Outputs script tag.
	 *
	 * @return void
	 */
	private function render_inline_script(): void {
		PRV_Call_Drawer_Script::render(
			wp_create_nonce( PRV_Call_Detail_Ajax::NONCE_ACTION ),
			admin_url( 'admin-ajax.php' )
		);
	}
}
