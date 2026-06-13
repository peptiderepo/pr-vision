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
