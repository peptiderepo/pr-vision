# Changelog — PR Vision

All notable changes to this project will be documented in this file.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
Versioning: [Semantic Versioning](https://semver.org/)

---

## [0.2.3] — 2026-06-15

UI-editable provider API key: encrypted at rest, write-only, wp-config precedence.
Output-escaping hardening in key manager renderer (`esc_url`/`esc_attr`/literal-conditional on all echoed values).

### Added

- **`PRV_Key_Store`** (`includes/core/class-prv-key-store.php`): single key resolver with
  precedence chain — `PRV_OPENROUTER_API_KEY` constant → encrypted admin option `prv_provider_key_enc` → none.
  `get_key()` is the sole key-access point for all probe and test paths.
- **`PRV_Crypto_Helper`** (`includes/core/class-prv-crypto-helper.php`): low-level
  symmetric encryption/decryption. Prefers libsodium `sodium_crypto_secretbox`
  (XSalsa20-Poly1305); falls back to OpenSSL AES-256-GCM. Encryption key is derived
  from WP salts (`AUTH_KEY` + `SECURE_AUTH_KEY`) via SHA-256 — environment-specific,
  never stored. Storage format: `hex(nonce):hex(ciphertext)`.
- **`PRV_Key_Manager_Renderer`** (`includes/core/class-prv-key-manager-renderer.php`):
  renders the write-only "Provider API Key" card in Settings. Shows source status only
  ("Set via wp-config (takes precedence)" / "Set via admin" / "Not set") plus last-run
  health. Password input always renders empty. Constant defined → input disabled with note.
- **`PRV_Key_Test_Ajax`** (`includes/core/class-prv-key-test-ajax.php`): AJAX handler for
  the "Test key" button. Resolves key via `PRV_Key_Store::get_key()`, runs one cheap probe,
  reports valid/invalid with `aria-live` result; key never in response.
- **Key set/remove POST actions** in `PRV_Settings_Controller`:
  `handle_key_set()` (encrypts + stores; empty input = no-op) and `handle_key_remove()`.
- **`prv_key_set`, `prv_key_remove`, `prv_key_error`** notices in `PRV_Settings_Renderer`.
- **`wp_ajax_prv_test_key`** hook registered in `PRV_Settings_Page` → dispatches to `PRV_Key_Test_Ajax`.
- **Tests** (`tests/unit/test-key-store.php`): encrypt↔decrypt round-trip, wrong-key fails
  silently, resolver precedence (constant > option > none), write-only render emits no key,
  Remove clears option, constant takes precedence.

### Changed

- **`PRV_OpenRouter_Provider`**: removed direct `PRV_OPENROUTER_API_KEY` constant reads;
  `probe()` now calls `PRV_Key_Store::get_key()`. New `probe_with_key(string $q, string $key)`
  method for the test path (PRV_Key_Test_Ajax injects the resolved key). `is_configured()`
  now checks `PRV_Key_Store::get_key() !== ''`.
- **`PRV_Perplexity_Provider`**: same key-resolver migration — `probe()` calls `PRV_Key_Store::get_key()`.
  `is_configured()` checks the store. Direct constant reads removed.
- **`PRV_Model_Test_Ajax`**: resolves key via `PRV_Key_Store::get_key()` instead of checking
  the constant directly. Returns a specific "key not configured — set via Settings" message
  when no key is available.
- **`PRV_Settings_Renderer`**: replaced read-only `render_api_key_status()` with a call to
  `PRV_Key_Manager_Renderer::render()`. Added key-action notices. Provider API Key card is
  now separate from the Probe Configuration form.
- **`PRV_Settings_Page`**: added `NONCE_KEY` + `NONCE_KEY_TEST` constants; registered
  `admin_post_prv_key_set`, `admin_post_prv_key_remove`, `wp_ajax_prv_test_key`.
- **`uninstall.php`**: updated comment to explicitly list `prv_provider_key_enc` as a v0.2.3
  addition purged by the `prv_` wildcard DELETE.
- **`tests/unit/test-uninstall.php`**: seeds + asserts `prv_provider_key_enc` purge.

### Security

- Plaintext API key never stored in any option, transient, log, or echoed to the browser.
- The encrypted option (`prv_provider_key_enc`) is ciphertext only; decryption is server-side
  at probe time only. The encryption key is derived from WP salts — not stored.
- `manage_options` + nonce required for all key actions (set, remove, test).
- `wp-config` constant always takes precedence; admin path is only active when absent.

---

## [0.2.2] — 2026-06-14

Kill white WP admin gutters around the dark "Assay" UI — CSS only, no logic change.

### Fixed

- **Admin chrome (dashboard + settings):** `#wpcontent`, `#wpbody`, and `#wpbody-content` now receive the dark page background (`#14181C`) on both PR Vision screens. WP's default `20px` left padding on `#wpcontent` is removed so no light gutter appears between the left menu and the dark content area.
- **WP admin bar / first-element gap:** `#wpbody` dark fill removes the white strip between the admin bar and the first card.
- **Page `<h1>` header:** `.wrap > h1` colour set to the light text token (`#EEF2F5`) so the dashboard heading renders on dark, not white.
- **Proxy-note banner (dashboard):** `.notice.prv-proxy-note` overridden to dark surface (`#1C2228`) with teal left border — banner now sits on dark bg matching the surrounding cards.
- **Scope:** entire CSS block delivered via `wp_add_inline_style('wp-admin', …)` inside `PRV_Admin_Page::enqueue_assets()`, which is already guarded by `strpos($hook, 'pr-vision')`. Styles are absent on every other WP admin page. WP admin bar and left menu are not touched.

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

---

## [0.2.1] — 2026-06-14

Dashboard visuals + deploy pipeline.

### Added

- **KPI bento bar on dashboard:** Three-tile band replacing the flat meta-bar. Score tile carries a **run-health pill** (dot + word: "All models healthy" / "N model(s) degraded" / "No run yet") sourced from `PRV_Model_Registry` health fields via `PRV_Ai_Visibility_Collector::collect_model_health()`. Cost tile carries the MTD meter + optional truncation badge. Last-run tile shows timestamp.
- **Config-change trendline marker:** Inline Chart.js plugin (`configMarker`) draws a vertical dashed orange line + pin circle at every `config_version` boundary in the trendline data. A legend swatch and a plain-language annotation note appear below the chart when breaks exist.
- **`PRV_Dashboard_Renderer`:** New class (`includes/panel/class-prv-dashboard-renderer.php`) owns the bento tiles and trendline rendering; `PRV_Ai_Visibility_Panel` delegates to it. Split keeps all files ≤ 300 lines.
- **Collector extensions:** `PRV_Ai_Visibility_Collector::collect()` now returns two new keys: `last_run_counts` (per-model health map from `PRV_Model_Registry`) and `config_versions` (all version records from `PRV_Config_Version`). The trendline query now includes `MIN(config_version)` per `run_id`.
- **Admin page CSS:** `PRV_Admin_Page::get_dashboard_css()` returns the "Assay" dark palette CSS (CSS custom-property palette, bento grid, health pills, chart legend swatches, cfg-note, standings status chips) scoped to `.prv-*` selectors.
- **`.github/workflows/deploy.yml`:** Standalone rsync deploy — on push to `main`: runs CI as prerequisite, rsyncs the plugin to `~/domains/peptiderepo.com/public_html/wp-content/plugins/pr-vision/` (excludes `.git .github tests phpunit.xml.dist composer.json composer.lock vendor phpcs.xml.dist *.md`), purges LiteSpeed cache via WP-CLI, health-checks (200 or 403). Uses repo secrets `SSH_HOST`/`SSH_USERNAME`/`SSH_PRIVATE_KEY`/`SSH_PORT`. Standalone — does NOT wire into the smoke-gated shared pipeline.
- **`tests/unit/test-dashboard-panel.php`:** 27 assertions covering: `derive_health_pill_state` (all states + edge cases), `PRV_Dashboard_Renderer::build_delta_html` (up/down/flat/null), collector `config_version` field on trendline rows, collector `last_run_counts` and `config_versions` keys, null config_version graceful handling, model health payload from registry, trendline output capture (marker present/absent, noscript fallback element).

### Changed

- `PRV_Ai_Visibility_Panel` refactored: bento and trendline rendering extracted to `PRV_Dashboard_Renderer`; panel is now a thin orchestrator (237 lines). No interface change.
- `PRV_Admin_Page::enqueue_assets()` now calls `get_dashboard_css()` for the "Assay" palette CSS instead of the legacy inline style block.
- Version bumped to **0.2.1**.
