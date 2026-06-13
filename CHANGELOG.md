# Changelog — Peptide GEO Monitor

All notable changes to this project will be documented in this file.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
Versioning: [Semantic Versioning](https://semver.org/)

---

## [0.1.0] — 2026-06-13

**Initial release — AI-visibility dashboard.**

### Added

- `PGM_Probe_Provider` interface + `PGM_Probe_Result` value object.
- `PGM_Perplexity_Provider`: Perplexity `sonar` via OpenRouter/Cloudflare AI Gateway; parses `citations[]` array.
- `PGM_OpenRouter_Provider`: generic parameterised provider for GPT-search and Gemini-search models; parses annotations + inline URL regex fallback.
- `PGM_Gateway_Client`: shared CF AI Gateway HTTP client with exponential-backoff retry and cURL auth injection (mirrors PRAutoBlogger pattern).
- `PGM_Citation_Detector`: domain extraction from citation arrays/URLs, `is_cited()`, `get_our_position()`.
- `PGM_Cost_Ledger`: month-to-date spend from DB, `can_afford()` pre-check, `update_row_cost()` settlement.
- `PGM_Probe_Runner`: full peptides × intents × models run with budget cap abort, in-run cache, and partial-run persistence.
- `PGM_Table_Manager`: `{prefix}pgm_ai_visibility` via `dbDelta`, schema-version option, `drop_table()` for uninstall.
- `PGM_Config`: typed getters + `seed_defaults()` with ~12 peptides, 3 intents, 3 models.
- `PGM_Cron`: weekly `pgm_weekly_probe` WP-Cron; `schedule_weekly()` / `clear_schedule()`.
- `PGM_Data_Collector` + `PGM_Dashboard_Panel` interfaces (collector/panel seam).
- `PGM_Collector_Registry`: singleton registry for future extensibility.
- `PGM_Ai_Visibility_Collector`: trendline (per-run visibility score), standings (per-peptide latest), MTD cost.
- `PGM_Ai_Visibility_Panel`: proxy-metric note, Chart.js trendline, per-peptide standings table, MTD cost bar, "Run now" button.
- `PGM_Admin_Page`: "GEO Monitor" top-level menu, Chart.js enqueue, Run now POST handler (nonce + `manage_options`).
- `uninstall.php`: DROP TABLE + DELETE `pgm_*` options + `wp_clear_scheduled_hook`.
- Unit tests: citation detection, score formula, cost ledger + budget cap abort, provider response parsing (mock HTTP), cron registration, uninstall purge.
- `ARCHITECTURE.md`, `CONVENTIONS.md`, `CONTEXT.md` (incl. visibility score formula), `README.md`.
- CI: PHP lint (8.1/8.2/8.3) + PHPCS + 300-line check (mirrors `peptide-repo-core`).
- Seed config: 12 peptides, 3 prompt intents, Perplexity sonar + GPT-4o-search + Gemini-2.0-Flash.
