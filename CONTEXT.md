# Context — Domain Glossary (Peptide GEO Monitor)

**Version:** 0.1.0 | **Last updated:** 2026-06-13

This file is the ubiquitous-language glossary for `peptide-geo-monitor`. Every term below has a precise meaning within this codebase. AI agents and human contributors should use this terminology consistently.

---

## Core domain terms

### AI Visibility
Whether and where peptiderepo.com appears in AI assistant responses to peptide-related queries. The core metric this plugin measures.

**Code identifiers:** `PGM_Ai_Visibility_Collector`, `PGM_Ai_Visibility_Panel`, table column `cited`, option prefix `pgm_`.

### Probe
A single server-side LLM API call with a specific `(peptide, intent, model)` combination. The result is a `PGM_Probe_Result` value object.

**Code identifiers:** `PGM_Probe_Provider::probe()`, `PGM_Probe_Runner::run()`.

### Probe Run
A batch execution of all configured `peptides × prompt_intents × models`. Identified by a `run_id` (UUID). One cron tick = one run; the "Run now" button also triggers a run.

**Code identifiers:** `PGM_Probe_Runner::run()`, `run_id` column in `pgm_ai_visibility`.

### Peptide
A compound being tracked (e.g. BPC-157, TB-500). Stored as a slug + label pair in the `pgm_peptides` option.

**Code identifiers:** `pgm_peptides` option, `peptide_slug` + `peptide_label` columns.

### Prompt Intent
A query template describing a searcher's intent (e.g. "what is {peptide}", "{peptide} reconstitution guide"). The `{peptide}` placeholder is replaced with the peptide's label at runtime.

**Code identifiers:** `pgm_prompt_intents` option, `prompt_intent` column.

### Citation / Cited
A probe is **cited** when `peptiderepo.com` appears in the LLM's source/citation list for that response. The `cited` column is `TINYINT(1)` (1 = cited, 0 = not cited).

**Code identifiers:** `PGM_Citation_Detector::is_cited()`, `cited` column, `PGM_Probe_Result::is_cited()`.

### Source Domains
The list of domains returned by the LLM as its sources for a given response (e.g. `["peptiderepo.com", "examine.com"]`). Stored as JSON in `source_domains`.

**Code identifiers:** `PGM_Citation_Detector::parse_domains()`, `PGM_Probe_Result::get_source_domains()`, `source_domains` column.

### Our Position
The 1-based rank of `peptiderepo.com` in the source domains list for a cited probe (`NULL` when not cited). Position 1 = we appear first.

**Code identifiers:** `PGM_Citation_Detector::get_our_position()`, `our_position` column, `PGM_Probe_Result::get_our_position()`.

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
- **Stored:** Computed from aggregate DB rows per run in `PGM_Ai_Visibility_Collector::compute_score()`, not persisted as a column (recomputed on page load).

**Code identifiers:** `PGM_Ai_Visibility_Collector::compute_score()`, trendline data in `collect()`.

### Monthly Budget Cap
The maximum USD spend on LLM API calls in a calendar month. Stored in `pgm_monthly_budget_usd` (default `$5.00`). The runner checks `PGM_Cost_Ledger::can_afford()` **before** each probe and stops gracefully when the cap is reached.

**Code identifiers:** `PGM_Cost_Ledger`, `pgm_monthly_budget_usd` option, `PGM_DEFAULT_MONTHLY_BUDGET_USD` constant.

### Provider
An implementation of `PGM_Probe_Provider` that wraps one LLM backend. v1 ships `PGM_Perplexity_Provider` (sonar) and `PGM_OpenRouter_Provider` (parameterised).

**Code identifiers:** `interface-pgm-probe-provider.php`, `PGM_Perplexity_Provider`, `PGM_OpenRouter_Provider`.

### Collector / Panel Seam
The extensibility mechanism: `PGM_Data_Collector` produces data; `PGM_Dashboard_Panel` renders it. Both are registered with `PGM_Collector_Registry`. v1 ships only the AI-visibility pair; future SEO categories add new pairs without touching the shell.

**Code identifiers:** `interface-pgm-data-collector.php`, `interface-pgm-dashboard-panel.php`, `PGM_Collector_Registry`.

### GEO
Generative Engine Optimization — the practice of improving a brand's visibility in AI-generated responses. This plugin is the measurement instrument for peptiderepo.com's GEO program.

### Proxy Metric
The plugin's scores are API-probe measurements, not real-world consumer LLM usage. The admin page labels these explicitly as "a directional proxy, not the consumer ChatGPT/Gemini apps."

**Code identifiers:** `PGM_Ai_Visibility_Panel::render_proxy_note()`.

### MTD Cost
Month-to-date spend in USD, summed from the `cost_usd` column for rows in the current calendar month.

**Code identifiers:** `PGM_Cost_Ledger::get_month_to_date_usd()`.
