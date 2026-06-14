# Changelog — PR Vision

All notable changes to this project will be documented in this file.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
Versioning: [Semantic Versioning](https://semver.org/)

---

## [0.1.1] — 2026-06-13

Rename to PR Vision; fix www-strip in competitor display; PHPCS gating; remove dead cache.

### Changed

- **Rename:** plugin, repo, slug, class prefix, option/hook/table prefix all updated from `pgm_`/`PGM_` to `prv_`/`PRV_` and from `peptide-geo-monitor`/`GEO Monitor` to `pr-vision`/`PR Vision`. No data migration required (no `pgm_*` data existed).

### Fixed

- **P2-A:** `ltrim($host, 'www.')` character-mask bug in `PRV_Citation_Detector::parse_domains()` replaced with `str_starts_with($host, 'www.') ? substr($host, 4) : $host`. Domains starting with `w` (e.g. `wikipedia.org`, `webmd.com`) are no longer corrupted in competitor standings.
- **P2-C:** Removed dead in-run cache from `PRV_Probe_Runner::run()`. The `peptides × intents × models` loop visits each `(slug, intent, model)` triplet exactly once, so the cache could never produce a hit on a single traversal.

### CI

- **P2-B:** Dropped `continue-on-error: true` from the PHPCS step — WordPress Coding Standards now gates the build. PHPCS was already green on v0.1.0; this enforces the DoD for future commits.

### Tests

- Added four assertions to `test-citation-detector.php` covering `wikipedia.org`, `webmd.com` (non-www domains starting with `w`) and `www.examine.com` to lock in the P2-A fix.

---

## [0.1.0] — 2026-06-13

**Initial release — AI-visibility dashboard.**

### Added

- `PRV_Probe_Provider` interface + `PRV_Probe_Result` value object.
- `PRV_Perplexity_Provider`: Perplexity `sonar` via OpenRouter/Cloudflare AI Gateway; parses `citations[]` array.
- `PRV_OpenRouter_Provider`: generic parameterised provider for GPT-search and Gemini-search models; parses annotations + inline URL regex fallback.
- `PRV_Gateway_Client`: shared CF AI Gateway HTTP client with exponential-backoff retry and cURL auth injection (mirrors PRAutoBlogger pattern).
- `PRV_Citation_Detector`: domain extraction from citation arrays/URLs, `is_cited()`, `get_our_position()`.
- `PRV_Cost_Ledger`: month-to-date spend from DB, `can_afford()` pre-check, `update_row_cost()` settlement.
- `PRV_Probe_Runner`: full peptides × intents × models run with budget cap abort and partial-run persistence.
- `PRV_Table_Manager`: `{prefix}prv_ai_visibility` via `dbDelta`, schema-version option, `drop_table()` for uninstall.
- `PRV_Config`: typed getters + `seed_defaults()` with ~12 peptides, 3 intents, 3 models.
- `PRV_Cron`: weekly `prv_weekly_probe` WP-Cron; `schedule_weekly()` / `clear_schedule()`.
- `PRV_Data_Collector` + `PRV_Dashboard_Panel` interfaces (collector/panel seam).
- `PRV_Collector_Registry`: singleton registry for future extensibility.
- `PRV_Ai_Visibility_Collector`: trendline (per-run visibility score), standings (per-peptide latest), MTD cost.
- `PRV_Ai_Visibility_Panel`: proxy-metric note, Chart.js trendline, per-peptide standings table, MTD cost bar, "Run now" button.
- `PRV_Admin_Page`: "PR Vision" top-level menu, Chart.js enqueue, Run now POST handler (nonce + `manage_options`).
- `uninstall.php`: DROP TABLE + DELETE `prv_*` options + `wp_clear_scheduled_hook`.
- Unit tests: citation detection, score formula, cost ledger + budget cap abort, provider response parsing (mock HTTP), cron registration, uninstall purge.
- `ARCHITECTURE.md`, `CONVENTIONS.md`, `CONTEXT.md` (incl. visibility score formula), `README.md`.
- CI: PHP lint (8.1/8.2/8.3) + PHPCS + 300-line check (mirrors `peptide-repo-core`).
- Seed config: 12 peptides, 3 prompt intents, Perplexity sonar + GPT-4o-search + Gemini-2.0-Flash.

---

## [0.2.0] — 2026-06-14

Admin/Settings interface + correctness fixes. All adversarial-QA must-fixes addressed.

### Added

- **Model manager (`prv_models` v2):** CRUD for models via Settings page — add, enable/disable, remove, edit, without a code deploy. Per-model: provider, slug, enabled flag, operator note, run-health, Test button.
- **`PRV_Model_Registry`:** manages the `prv_models` option in v2 rich-object format; migration from v0.1.x flat-string format is versioned, idempotent, and tested.
- **`PRV_Upgrader`:** runs all pending migrations on every `plugins_loaded` (not just activation) so live-upgraded installs get migrated automatically.
- **`PRV_Config_Version`:** stamps every run with the scored config (models × peptides × intents); bumps on scoring-relevant saves; `get_all_versions()` for trendline annotations.
- **`PRV_Run_Lock`:** transient-based distributed lock preventing concurrent cron + "Run now" executions; `acquire()` / `release()` / `is_locked()`.
- **`PRV_Settings_Page`:** "PR Vision → Settings" sub-menu with POST handlers for settings save, model CRUD, Run now, Test model AJAX. All actions `manage_options` + nonce gated.
- **`PRV_Settings_Renderer`:** renders the dark "Assay" settings page (CSS variables, sticky save-bar with scoring-change warning, projected cost inset, API-key status, Run now).
- **`PRV_Model_Manager_Table`:** renders the model manager table with health badges, keyboard-sortable `<button aria-sort>` headers, disabled-toggle for retired rows, Test chips with `aria-live`.
- **`PRV_Model_Test_Ajax`:** rate-limited AJAX handler for point-in-time model-slug validation.
- **Per-model run-health:** `PRV_Model_Registry::update_health()` called at end of each run; "Retired?" badge when `probed=0 && errors>0`.
- **Metric comparability:** config-version stamp on every DB row (`config_version` INT column); bump + warning on scoring-relevant save; break annotation hook for dashboard trendline.
- **Projected cost at save:** `PRV_Config::get_projected_cost()` returns per_run / per_month / probe_count / over_cap; displayed live on settings page.
- **Budget truncation state:** `prv_last_run_truncated` option set when a run hits the cap mid-way; surfaced in run-done notices and dashboard data.
- **Cadence reschedule:** `PRV_Cron::reschedule()` clears + re-adds the WP-Cron event on cadence change; next-run timestamp shown.
- **API-key three-state:** "Not defined" / "Defined — last run OK" / "Defined — last run failed" derived from `prv_api_key_status` ledger option; never displays the key.
- **Design criticals (v2 mockups):** dark ink focus ring on bright fills; disabled-row text at full AA (no opacity collapse), toggle genuinely `disabled`; save-bar-level warning with `role=alert`; "Not yet" chip `#B4BDC7` (6.83:1 AA); keyboard-operable sortable headers (`<button>` + `aria-sort`); `aria-live="polite"` on Test chip + Run-now status; sticky save-bar `z-index:50`; Run-now double-fire guard; `@media print` light overrides.
- New unit tests: `test-model-registry.php` (migration, CRUD, health), `test-run-lock.php` (acquire/release/sentinel), `test-config-version.php` (hash, bump, stamp), `test-projected-cost.php` (probe_count, over_cap, truncation), `test-cron-reschedule.php` (reschedule, idempotent, tick guard).

### Changed

- `PRV_Config::get_models()` now delegates to `PRV_Model_Registry::get_enabled_slugs()`.
- `PRV_Config::seed_defaults()` no longer seeds `prv_models` directly (handled by `PRV_Upgrader`).
- `PRV_Config` adds `CADENCE_KEY` constant, `get_cadence()`, `get_projected_cost()`.
- `PRV_Probe_Runner::run()` acquires run-lock, stamps `config_version` on each row, updates model health, sets truncation option, returns `truncated` + `run_id` keys.
- `PRV_Table_Manager::create_table()` adds `config_version INT NULL` column; schema version bumped to 2.
- `PRV_Cron` adds `reschedule()`, `next_run_timestamp()`, cadence-aware `schedule()`; `handle_cron_tick()` skips if run-lock held.
- `PRV_Plugin::init()` boots `PRV_Upgrader::run()` first and registers `PRV_Settings_Page`.
- `uninstall.php` also purges `prv_` transients (run-lock etc.).
- Version bumped to **0.2.0**.

### CI

- **P1-A:** Removed `function defined()` and `function constant()` declarations from `tests/bootstrap.php` — they shadowed PHP built-ins and caused `Cannot redeclare` fatal on PHP 8.x, blocking all unit-test CI steps.
- **P1-B:** Re-escaped pre-escaped variables at echo site in `class-prv-model-manager-table.php`; added targeted `phpcs:ignore` with justification for `render_health_badge()` (returns pre-escaped HTML).
- **P1-C:** Added `phpcs:disable/enable WordPress.Security.NonceVerification.Missing` around `$_POST` blocks in all four `handle_*()` methods in `class-prv-settings-page.php`; nonce is verified via `require_admin_nonce()` private helper.
- **P1-D/E:** Expanded inline associative arrays to multi-line in `class-prv-config.php`, `class-prv-probe-runner.php`, `class-prv-settings-renderer.php`, `class-prv-model-test-ajax.php`; fixed param-comment spacing in `class-prv-model-registry.php`; capitalised docblock long-description first word.
- **PHPCS compliance:** Expanded remaining 5 inline associative arrays in `class-prv-settings-page.php` (`add_query_arg` calls + `PRV_Model_Registry::update` data array) to multi-line with trailing comma; corrected `@param` column alignment in `class-prv-probe-runner.php:update_api_key_status` and `class-prv-model-registry.php:update_health` to match longest-type rule. PHPCS: 0 errors / 0 warnings.
- **300-line split:** extracted `handle_save`, `handle_model_add`, `handle_model_update`, `handle_model_remove` from `class-prv-settings-page.php` (346 lines) into new `class-prv-settings-controller.php` (`PRV_Settings_Controller`); settings-page delegates via nonce callback. All `phpcs:disable/enable WordPress.Security.NonceVerification.Missing` wrappers carried with the moved handlers. Both files now ≤213 lines; no file exceeds 300 lines.
