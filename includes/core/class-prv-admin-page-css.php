<?php
/**
 * Dashboard CSS provider for the PR Vision admin page.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Provides the combined CSS for all PR Vision admin pages.
 *
 * Split from PRV_Admin_Page to keep that class under 300 lines.
 * Returns a single CSS string covering the dark "Assay" palette,
 * bento tiles, cards, chart helpers, subnav, and v0.3.0 drawer + log.
 *
 * Who triggers: PRV_Admin_Page::get_dashboard_css() → called via
 *               wp_add_inline_style() on admin_enqueue_scripts.
 * Dependencies: None.
 *
 * @see class-prv-admin-page.php — Caller.
 * @package PrVision
 */
class PRV_Admin_Page_Css {

	/**
	 * Return the full inline CSS string for all PR Vision screens.
	 *
	 * Side effects: None.
	 *
	 * @return string CSS string.
	 */
	public static function get(): string {
		return self::get_chrome_css() . self::get_component_css() . self::get_v030_css();
	}

	/**
	 * WP admin chrome overrides (kill white gutters around dark UI).
	 *
	 * @return string
	 */
	private static function get_chrome_css(): string {
		return '
/* === PR Vision — WP admin chrome: kill white wrappers (scoped via conditional enqueue) === */
#wpcontent{background:#14181C;padding-left:0;}
#wpbody-content{background:#14181C;min-height:calc(100vh - 32px);padding-bottom:40px;}
#wpbody{background:#14181C;}
.wrap>h1,.prv-settings-wrap~*>h1,.wp-heading-inline{color:#EEF2F5;}
.notice.prv-proxy-note{background:#1C2228;border-left-color:#34C0CA;color:#EEF2F5;}
.notice.prv-proxy-note p{color:#C2CCD6;}
';
	}

	/**
	 * Core component tokens (.prv-*) unchanged from prior versions.
	 *
	 * @return string
	 */
	private static function get_component_css(): string {
		return '
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
.prv-health-pill{position:absolute;top:14px;right:14px;display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:3px 9px 3px 7px;border-radius:999px;}
.prv-health-dot{width:7px;height:7px;border-radius:50%;flex:0 0 7px;}
.prv-health--ok{background:rgba(155,230,53,.15);color:#9BE635;} .prv-health--ok .prv-health-dot{background:#9BE635;}
.prv-health--warn{background:rgba(255,157,77,.15);color:#FFB36E;} .prv-health--warn .prv-health-dot{background:#FF9D4D;}
.prv-health--neutral{background:rgba(136,147,160,.14);color:#B4BDC7;} .prv-health--neutral .prv-health-dot{background:#8893A0;}
.prv-cost-row{display:flex;align-items:baseline;gap:4px;margin-top:auto;}
.prv-cost-big{font-size:28px;font-weight:700;color:#EEF2F5;font-variant-numeric:tabular-nums;}
.prv-cost-cap{font-size:13px;color:#9AA7B2;}
.prv-meter{height:10px;border-radius:999px;background:#2A333C;margin-top:8px;border:1px solid #333D47;overflow:hidden;}
.prv-meter>span{display:block;height:100%;border-radius:999px;background:#34C0CA;transition:width .3s ease;}
.prv-meter--capped>span{background:#FF9D4D;}
.prv-trunc-badge{display:flex;align-items:flex-start;gap:7px;margin-top:10px;background:rgba(255,157,77,.15);border:1px solid rgba(255,157,77,.42);border-radius:6px;padding:8px 10px;font-size:12px;color:#EEF2F5;line-height:1.4;}
.prv-trunc-icon{color:#FFB36E;font-size:14px;flex:0 0 16px;margin-top:1px;}
.prv-trunc-badge b{color:#FFB36E;}
.prv-tile-ts{font-size:20px;font-weight:700;color:#EEF2F5;margin-top:auto;line-height:1.2;}
.prv-tile-meta{font-size:12.5px;color:#9AA7B2;margin-top:6px;}
.prv-card{background:#1C2228;border:1px solid #3A4651;border-radius:12px;margin:0 0 20px;}
.prv-card-head{display:flex;align-items:center;justify-content:space-between;padding:18px 22px 0;}
.prv-card-head h2{font-size:16px;font-weight:600;color:#EEF2F5;margin:0;}
.prv-card-body{padding:10px 22px 20px;}
.prv-chartbox{position:relative;height:300px;max-height:300px;padding:14px 8px 0;overflow:hidden;}
.prv-chartbox canvas{max-height:286px;}
.prv-chart-fallback{display:none;height:100%;align-items:center;justify-content:center;text-align:center;color:#9AA7B2;font-size:13px;padding:0 24px;}
.prv-chartbox.prv-noscript .prv-chart-fallback{display:flex;}
.prv-chartbox.prv-noscript canvas{display:none;}
.prv-chartcap{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:8px 22px 4px;font-size:12.5px;color:#9AA7B2;}
.prv-chartcap-item{display:inline-flex;align-items:center;gap:6px;}
.prv-leg-solid{width:20px;height:0;border-top:3px solid #34C0CA;display:inline-block;}
.prv-leg-dash{width:20px;height:0;border-top:2px dashed #B6F25A;display:inline-block;}
.prv-cfg-note{display:flex;gap:10px;align-items:flex-start;margin:8px 22px 14px;background:rgba(255,157,77,.15);border:1px solid rgba(255,157,77,.42);border-radius:8px;padding:11px 14px;color:#EEF2F5;font-size:13px;line-height:1.45;}
.prv-cfg-note-icon{color:#FFB36E;font-style:normal;flex:0 0 16px;margin-top:1px;}
.prv-cfg-note b{color:#FFB36E;}
.prv-standings{margin:0;}
.prv-status{display:inline-flex;align-items:center;gap:4px;font-size:12.5px;font-weight:600;padding:2px 8px;border-radius:999px;}
.prv-status--cited{background:rgba(155,230,53,.15);color:#9BE635;}
.prv-status--not-yet{background:rgba(136,147,160,.14);color:#B4BDC7;}
.prv-proxy-note{margin:10px 0;}
';
	}

	/**
	 * V0.3.0 additions: subnav, costs, call-log, drawer components.
	 *
	 * @return string
	 */
	private static function get_v030_css(): string {
		return '
/* === v0.3.0: subnav + costs + call log === */
.prv-subnav{display:flex;gap:2px;margin:0 0 18px;border-bottom:1px solid #3A4651;padding-bottom:0;}
.prv-subnav-tab{display:inline-block;padding:8px 16px;font-size:13px;font-weight:500;color:#9AA7B2;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s;}
.prv-subnav-tab:hover{color:#EEF2F5;}
.prv-subnav-tab--active{color:#34C0CA;border-bottom-color:#34C0CA;}
.prv-page-wrap{padding:0 20px 40px;background:#14181C;min-height:100vh;}
.prv-page-header{margin:18px 0 14px;}
.prv-page-title{font-size:20px;font-weight:700;color:#EEF2F5;margin:0 0 4px;}
.prv-page-sub{font-size:13px;color:#9AA7B2;margin:0;}
.prv-retention-note{font-size:12px;color:#9AA7B2;margin:4px 0 0;font-style:italic;}
.prv-cap-row{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;}
.prv-cap-left{display:flex;align-items:baseline;gap:6px;}
.prv-cap-right{display:flex;flex-direction:column;align-items:flex-end;gap:4px;}
.prv-cap-projected{display:flex;flex-direction:column;align-items:flex-end;}
.prv-cap-proj-label{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9AA7B2;}
.prv-cap-proj-val{font-size:18px;font-weight:600;font-variant-numeric:tabular-nums;color:#EEF2F5;}
.prv-cap-days{font-size:12px;color:#9AA7B2;}
.prv-cap-reconcile{font-size:12px;color:#9AA7B2;margin-top:10px;}
.prv-tile-big--sm{font-size:28px;}
.prv-filter-bar{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;align-items:center;}
.prv-filter-select,.prv-filter-date{background:#1C2228;border:1px solid #3A4651;color:#EEF2F5;border-radius:6px;padding:5px 10px;font-size:13px;}
.prv-results-count{font-size:12.5px;color:#9AA7B2;margin:0 0 10px;}
.prv-callog-table th,.prv-callog-table td{white-space:nowrap;}
.prv-callog-table{min-width:900px;}
.prv-tabnum{font-variant-numeric:tabular-nums;}
.prv-muted{color:#9AA7B2;}
.prv-mono{font-family:monospace;font-size:12px;}
.prv-mono-sm{font-size:11px;}
.prv-chip{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:600;padding:2px 8px;border-radius:999px;}
.prv-chip--ok{background:rgba(52,192,202,.15);color:#34C0CA;}
.prv-chip--error{background:rgba(255,107,94,.15);color:#FF6B5E;}
.prv-call-row{cursor:pointer;}
.prv-call-row:hover{background:rgba(52,192,202,.07);}
.prv-call-row:focus{outline:2px solid #34C0CA;outline-offset:-2px;}
.prv-pagination{display:flex;align-items:center;gap:10px;margin-top:14px;flex-wrap:wrap;}
.prv-page-btn{display:inline-block;padding:5px 12px;border:1px solid #3A4651;border-radius:6px;font-size:12.5px;color:#EEF2F5;text-decoration:none;}
.prv-page-btn:hover{border-color:#34C0CA;color:#34C0CA;}
.prv-page-btn--disabled{color:#4A5563;border-color:#2A333C;background:#1C2228;cursor:not-allowed;}
.prv-page-info,.prv-page-per{font-size:12.5px;color:#9AA7B2;}
.prv-drawer{position:fixed;right:0;top:32px;height:calc(100vh - 32px);width:480px;background:#1C2228;border-left:1px solid #3A4651;z-index:9999;overflow-y:auto;transform:translateX(100%);transition:transform .16s ease;}
.prv-drawer:not([hidden]){transform:translateX(0);}
@media(prefers-reduced-motion:reduce){.prv-drawer{transition:none;}}
@media(max-width:600px){.prv-drawer{width:100%;}}
.prv-drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:9998;opacity:0;transition:opacity .16s ease;}
.prv-drawer:not([hidden])~.prv-drawer-overlay{opacity:1;}
.prv-drawer-header{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #3A4651;}
.prv-drawer-close{background:none;border:1px solid #3A4651;color:#9AA7B2;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12.5px;}
.prv-drawer-close:hover{color:#EEF2F5;border-color:#9AA7B2;}
.prv-drawer-title{font-size:13px;font-weight:600;color:#EEF2F5;}
.prv-drawer-body{padding:16px 18px;}
.prv-drawer-meta-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;}
.prv-drawer-id{font-size:16px;font-weight:700;color:#EEF2F5;}
.prv-drawer-ts{font-size:12px;color:#9AA7B2;font-variant-numeric:tabular-nums;}
.prv-drawer-topline{font-size:13px;color:#C2CCD6;margin-bottom:10px;}
.prv-drawer-peptide{font-weight:600;color:#EEF2F5;}
.prv-drawer-dl{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;font-size:12.5px;margin:12px 0 16px;}
.prv-drawer-dl dt{color:#9AA7B2;font-weight:500;}
.prv-drawer-dl dd{color:#EEF2F5;margin:0;}
.prv-drawer-section{margin-bottom:18px;}
.prv-drawer-section-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.prv-drawer-section-label{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9AA7B2;}
.prv-copy-btn{background:none;border:1px solid #3A4651;color:#9AA7B2;border-radius:4px;padding:2px 8px;cursor:pointer;font-size:11.5px;}
.prv-copy-btn:hover{color:#34C0CA;border-color:#34C0CA;}
.prv-code-block{background:#14181C;border:1px solid #2A333C;border-radius:6px;padding:12px;font-size:11.5px;line-height:1.55;color:#C2CCD6;overflow-y:auto;max-height:320px;white-space:pre-wrap;word-break:break-word;margin:0;}
.prv-drawer-notice{display:flex;gap:10px;align-items:flex-start;background:rgba(136,147,160,.1);border:1px solid #3A4651;border-radius:8px;padding:12px 14px;font-size:12.5px;color:#C2CCD6;line-height:1.45;}
.prv-notice-icon{font-size:16px;color:#9AA7B2;flex:0 0 18px;}
.prv-error-block{display:flex;gap:10px;align-items:flex-start;background:rgba(255,107,94,.12);border:1px solid rgba(255,107,94,.35);border-radius:8px;padding:12px 14px;font-size:12.5px;color:#EEF2F5;line-height:1.45;}
.prv-error-icon{font-size:16px;color:#FF6B5E;flex:0 0 18px;}
.prv-drawer-err{color:#FF8A80;font-size:13px;}
.prv-skel-line{background:#2A333C;border-radius:4px;height:12px;margin-bottom:8px;animation:prvPulse 1.2s ease-in-out infinite;}
.prv-skel-line--wide{width:80%;} .prv-skel-line--med{width:55%;} .prv-skel-line--sm{width:35%;}
@keyframes prvPulse{0%,100%{opacity:.5;}50%{opacity:1;}}
.prv-seg{display:flex;gap:2px;margin-bottom:14px;flex-wrap:wrap;}
.prv-seg-btn{display:inline-block;padding:5px 12px;border:1px solid #3A4651;border-radius:6px;font-size:12.5px;color:#9AA7B2;text-decoration:none;}
.prv-seg-btn:hover{color:#EEF2F5;border-color:#9AA7B2;}
.prv-seg-btn--active{background:#34C0CA;color:#14181C;border-color:#34C0CA;font-weight:600;}
.prv-table-wrap table{width:100%;}
.prv-empty-msg{color:#9AA7B2;font-size:13px;padding:14px 0;}
.prv-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0;}
.prv-drawer-na{color:#9AA7B2;font-size:13px;font-style:italic;}
.prv-status--error{background:rgba(255,107,94,.15);color:#FF6B5E;}
.prv-field-hint{display:block;font-size:12px;color:#9AA7B2;margin-top:4px;}
';
	}
}
