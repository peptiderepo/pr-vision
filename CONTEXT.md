# Context — Domain Glossary (PR Vision)

**Version:** 0.1.1 | **Last updated:** 2026-06-13

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

### GEO
Generative Engine Optimization — the practice of improving a brand's visibility in AI-generated responses. This plugin is the measurement instrument for peptiderepo.com's GEO program.

### Proxy Metric
The plugin's scores are API-probe measurements, not real-world consumer LLM usage. The admin page labels these explicitly as "a directional proxy, not the consumer ChatGPT/Gemini apps."

**Code identifiers:** `PRV_Ai_Visibility_Panel::render_proxy_note()`.

### MTD Cost
Month-to-date spend in USD, summed from the `cost_usd` column for rows in the current calendar month.

**Code identifiers:** `PRV_Cost_Ledger::get_month_to_date_usd()`.
