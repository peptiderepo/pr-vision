# Context — Domain Glossary (PR Vision)

**Version:** 0.2.1 | **Last updated:** 2026-06-14

This file is the ubiquitous-language glossary for `pr-vision`. Every term below has a precise meaning within this codebase. AI agents and human contributors should use this terminology consistently.

---

## Core domain terms

### AI Visibility
Whether and where peptiderepo.com appears in AI assistant responses to peptide-related queries. The core metric this plugin measures.

**Code identifiers:** `PRV_Ai_Visibility_Collector`, `PRV_Ai_Visibility_Panel`, table column `cited`, option prefix `prv_`.

### Probe
A single server-side LLM API call with a specific `(peptide, intent, model)` combination. The result is a `PRV_Probe_Result` value object.

**Code identifiers:** `PRV_Probe_Provider::probe()`, `PRV_Probe_Runner::run()`.

### Probe Run
A batch execution of all configured `peptides × prompt_intents × models`. Identified by a `run_id` (UUID). One cron tick = one run; the "Run now" button also triggers a run.

**Code identifiers:** `PRV_Probe_Runner::run()`, `run_id` column in `prv_ai_visibility`.

### Peptide
A compound being tracked (e.g. BPC-157, TB-500). Stored as a slug + label pair in the `prv_peptides` option.

**Code identifiers:** `prv_peptides` option, `peptide_slug` + `peptide_label` columns.

### Prompt Intent
A query template describing a searcher's intent (e.g. "what is {peptide}", "{peptide} reconstitution guide"). The `{peptide}` placeholder is replaced with the peptide's label at runtime.

**Code identifiers:** `prv_prompt_intents` option, `prompt_intent` column.

### Citation / Cited
A probe is **cited** when `peptiderepo.com` appears in the LLM's source/citation list for that response. The `cited` column is `TINYINT(1)` (1 = cited, 0 = not cited).

**Code identifiers:** `PRV_Citation_Detector::is_cited()`, `cited` column, `PRV_Probe_Result::is_cited()`.

### Source Domains
The list of domains returned by the LLM as its sources for a given response (e.g. `["peptiderepo.com", "examine.com"]`). Stored as JSON in `source_domains`.

**Code identifiers:** `PRV_Citation_Detector::parse_domains()`, `PRV_Probe_Result::get_source_domains()`, `source_domains` column.

### Our Position
The 1-based rank of `peptiderepo.com` in the source domains list for a cited probe (`NULL` when not cited). Position 1 = we appear first.

**Code identifiers:** `PRV_Citation_Detector::get_our_position()`, `our_position` column, `PRV_Probe_Result::get_our_position()`.

### Visibility Score
A composite metric summarising peptiderepo.com's AI visibility for a run.

**Formula:**
```
base_score     = cited_probes / total_probes
position_bonus = Σ(1 / our_position) for each cited probe / total_probes
visibility_score = round((base_score + position_bonus) / 2, 4)
```

- **Range:** [0.0, 1.0]. Score 1.0 requires 100% citation rate at position 1 for all probes.
- **Why position-weighted?** Being cited at position 1 is more valuable than position 10. The 1/position term gives a graduated bonus: position 1 = 1.0, position 2 = 0.5, position 5 = 0.2, etc.
- **Stored:** Computed from aggregate DB rows per run in `PRV_Ai_Visibility_Collector::compute_score()`, not persisted as a column (recomputed on page load).

**Code identifiers:** `PRV_Ai_Visibility_Collector::compute_score()`, trendline data in `collect()`.

### Monthly Budget Cap
The maximum USD spend on LLM API calls in a calendar month. Stored in `prv_monthly_budget_usd` (default `$5.00`). The runner checks `PRV_Cost_Ledger::can_afford()` **before** each probe and stops gracefully when the cap is reached.

**Code identifiers:** `PRV_Cost_Ledger`, `prv_monthly_budget_usd` option, `PRV_DEFAULT_MONTHLY_BUDGET_USD` constant.

### Provider
An implementation of `PRV_Probe_Provider` that wraps one LLM backend. v1 ships `PRV_Perplexity_Provider` (sonar) and `PRV_OpenRouter_Provider` (parameterised).

**Code identifiers:** `interface-prv-probe-provider.php`, `PRV_Perplexity_Provider`, `PRV_OpenRouter_Provider`.

### Collector / Panel Seam
The extensibility mechanism: `PRV_Data_Collector` produces data; `PRV_Dashboard_Panel` renders it. Both are registered with `PRV_Collector_Registry`. v1 ships only the AI-visibility pair; future SEO categories add new pairs without touching the shell.

**Code identifiers:** `interface-prv-data-collector.php`, `interface-prv-dashboard-panel.php`, `PRV_Collector_Registry`.

### Config Version
A monotonically incrementing integer (stored in the `prv_config_versions` option) that is bumped whenever the active probe configuration changes (models list, peptides list, or prompt intents). Each row in `prv_ai_visibility` is stamped with the `config_version` at the time it was recorded. Rows from a different config version are excluded from scoring to ensure comparability.

**Code identifiers:** `PRV_Config_Version`, `bump_version_if_changed()`, `config_version` column, `prv_config_versions` option.

### Run Lock
A transient-backed mutex (`prv_run_lock`) that prevents concurrent probe runs. Acquired at the start of a run and released in a `finally` block. TTL is 1 hour to self-clear on fatal crash. Both the cron tick and the "Run now" button check `is_locked()` before dispatching.

**Code identifiers:** `PRV_Run_Lock`, `acquire()`, `release()`, `is_locked()`, transient `prv_run_lock`.

### Model Registry
A versioned option (`prv_models`) storing the list of LLM models to probe. Each entry is a rich object with `{id, provider, slug, enabled, note, health_status, health_probed, health_errors, health_run_id}`. The registry migrates v0.1.x flat-string arrays to v2 schema on upgrade. CRUD is exposed on the Settings page.

**Code identifiers:** `PRV_Model_Registry`, `run_migration_v2()`, `prv_models` option, `PRV_SCHEMA_VERSION` constant.

### Run Health
A per-model health status derived from the most recent probe run outcomes. Values: `healthy` (probes succeeded), `retired` (zero rows, errors occurred), `disabled` (model not enabled), `unknown` (not yet run). Displayed as a badge in the Model Manager table. Updated by `PRV_Model_Registry::update_health()` at end of each run.

**Code identifiers:** `health_status`, `health_probed`, `health_errors`, `health_run_id` fields; `PRV_Model_Registry::update_health()`; `render_health_badge()`.

### Projected Cost
The estimated monthly spend (in USD) based on the current configuration: `enabled_models × peptides × intents × $0.005/probe × runs_per_month`. Displayed on the Settings page as a cost inset. The `over_cap` flag is shown with `aria-live="polite"` when the projected cost would exceed the monthly budget.

**Code identifiers:** `PRV_Config::get_projected_cost()`, `per_run_usd`, `per_month_usd`, `probe_count`, `over_cap` return keys.

### Config-version Stamp
The act of recording the active `config_version` integer on each `prv_ai_visibility` row at insert time. Enables retrospective filtering: only rows with `config_version = active_version` are included in current-period scoring, preventing cross-config score pollution.

**Code identifiers:** `config_version` column in `prv_ai_visibility`, `PRV_Config_Version::get_active_version()`.

### Truncation State
When the monthly budget cap is reached mid-run, `prv_last_run_truncated` is set to `1`. This surfaces on the Settings page as a notice: "Last run was truncated by the monthly budget cap." The flag is cleared on the next successful full run. Probes after the cap are skipped (not errored) and counted in `skipped_budget`.

**Code identifiers:** `prv_last_run_truncated` option, `PRV_Cost_Ledger::can_afford()`, `skipped_budget` count, `truncated` flag in run counts.

### GEO
Generative Engine Optimization — the practice of improving a brand's visibility in AI-generated responses. This plugin is the measurement instrument for peptiderepo.com's GEO program.

### Proxy Metric
The plugin's scores are API-probe measurements, not real-world consumer LLM usage. The admin page labels these explicitly as "a directional proxy, not the consumer ChatGPT/Gemini apps."

**Code identifiers:** `PRV_Ai_Visibility_Panel::render_proxy_note()`.

### MTD Cost
Month-to-date spend in USD, summed from the `cost_usd` column for rows in the current calendar month.

**Code identifiers:** `PRV_Cost_Ledger::get_month_to_date_usd()`.

### Key Store (v0.2.3)
The subsystem that manages the OpenRouter API key with a precedence chain and encrypted-at-rest storage. Precedence: `PRV_OPENROUTER_API_KEY` wp-config constant (highest) → encrypted admin option `prv_provider_key_enc` → none. All key reads by providers and AJAX handlers route through `PRV_Key_Store::get_key()`. The plaintext key is never stored in any option, log, or client-side surface.

**Code identifiers:** `PRV_Key_Store`, `PRV_Key_Store::get_key()`, `PRV_Key_Store::store_key()`, `PRV_Key_Store::clear_key()`, `PRV_Key_Store::get_source()`, `prv_provider_key_enc` option.

### Write-only Key UI (v0.2.3)
The Settings page section that allows the operator to set/replace/clear the API key without the key ever being rendered in the browser. The password input always renders empty. The UI shows only the key *source* (wp-config / admin / not set) and the last-run health state. The key value is never output to page HTML, JS, REST responses, or logs.

**Code identifiers:** `PRV_Key_Manager_Renderer`, `PRV_Key_Test_Ajax`, `PRV_Settings_Controller::handle_key_set()`, `PRV_Settings_Controller::handle_key_remove()`.

### Encryption Key (v0.2.3)
The symmetric key used to encrypt/decrypt `prv_provider_key_enc`. Derived via SHA-256 from `AUTH_KEY + SECURE_AUTH_KEY` (or `wp_salt()` fallback) — environment-specific and never stored. Derived fresh at every encrypt/decrypt call.

**Code identifiers:** `PRV_Key_Store::derive_encryption_key()`, `PRV_Crypto_Helper::encrypt()`, `PRV_Crypto_Helper::decrypt()`.
