# Architecture — PR Vision

**Version:** 0.2.0 | **Last updated:** 2026-06-14

---

## 1. Overview

`pr-vision` is an internal WordPress plugin that runs configurable server-side LLM probes to measure whether peptiderepo.com appears in AI citations across core peptide queries. It stores a time-series in a custom table and renders an admin dashboard (trendline + standings) and a full Settings page (model manager + probe config).

**v1 scope:** AI-visibility only. The collector/panel seam is present so future data categories can be added as new classes.
**v2 additions:** Model manager CRUD (no deploy needed), config-version tracking, run-lock, projected cost, API-key three-state, dark "Assay" Settings UI.

---

## 2. File tree

```
pr-vision/
├── pr-vision.php          # Plugin boot: constants, autoloader, hooks
├── uninstall.php          # Full data teardown: DROP TABLE + DELETE prv_* options + transients
├── composer.json          # Dev deps (PHPCS only)
├── phpcs.xml.dist         # PHPCS config (WordPress standard)
├── includes/
│   ├── core/
│   │   ├── class-prv-autoloader.php          # SPL autoloader: PRV_* -> includes/**
│   │   ├── class-prv-plugin.php              # Orchestrator: boots sub-systems + upgrader
│   │   ├── class-prv-upgrader.php            # [v0.2] Runs all migrations on every plugins_loaded
│   │   ├── class-prv-activator.php           # Activation: table + defaults + cron
│   │   ├── class-prv-deactivator.php         # Deactivation: clear cron
│   │   ├── class-prv-table-manager.php       # dbDelta create/drop + config_version column (v0.2)
│   │   ├── class-prv-config.php              # Typed getters + seed_defaults() + get_projected_cost()
│   │   ├── class-prv-model-registry.php      # [v0.2] prv_models CRUD + v1->v2 migration + health
│   │   ├── class-prv-config-version.php      # [v0.2] Config-version stamps + bump on change
│   │   ├── class-prv-run-lock.php            # [v0.2] Transient-based run-lock (cron + Run now)
│   │   ├── class-prv-cron.php                # Probe cron: schedule/reschedule/clear; skip if locked
│   │   ├── class-prv-cost-ledger.php         # MTD cost + hard monthly cap enforcement
│   │   ├── class-prv-probe-result.php        # Immutable value object from a probe call
│   │   ├── class-prv-probe-runner.php        # Orchestrates run: lock + peptide*intent*model loop
│   │   ├── class-prv-collector-registry.php  # Singleton registry for collectors + panels
│   │   ├── class-prv-admin-page.php          # Dashboard page: menu, Run now, rendering
│   │   ├── class-prv-settings-page.php       # [v0.2] Settings page: menu, Run-now, AJAX dispatch; delegates POST to PRV_Settings_Controller
│   │   ├── class-prv-settings-controller.php # [v0.2] POST handler impl: save config, model CRUD (split from settings-page)
│   │   ├── class-prv-settings-renderer.php   # [v0.2] Settings HTML renderer (dark Assay theme)
│   │   ├── class-prv-model-manager-table.php # [v0.2] Model manager table + Add form renderer
│   │   └── class-prv-model-test-ajax.php     # [v0.2] Rate-limited AJAX handler for Test button
│   ├── providers/
│   │   ├── interface-prv-probe-provider.php  # probe(query): PRV_Probe_Result contract
│   │   ├── class-prv-gateway-client.php      # Cloudflare AI Gateway HTTP + retry
│   │   ├── class-prv-citation-detector.php   # Domain extraction + cite detection
│   │   ├── class-prv-perplexity-provider.php # Perplexity sonar via OpenRouter/gateway
│   │   └── class-prv-openrouter-provider.php # Generic OpenRouter (GPT-search, Gemini)
│   ├── collector/
│   │   ├── interface-prv-data-collector.php  # collect(): array seam
│   │   └── class-prv-ai-visibility-collector.php  # AI-visibility data from DB
│   └── panel/
│       ├── interface-prv-dashboard-panel.php # render(data): void seam
│       └── class-prv-ai-visibility-panel.php # Renders trendline + standings
├── tests/
│   ├── bootstrap.php               # WP-stub bootstrap (no PHPUnit)
│   └── unit/
│       ├── test-citation-detector.php   # Domain parsing + cite detection
│       ├── test-score-calc.php          # Visibility score formula
│       ├── test-cost-ledger.php         # MTD cost + budget cap
│       ├── test-provider-parsing.php    # Mock-HTTP provider response parsing
│       ├── test-cron.php                # Schedule/clear + activation/deactivation
│       ├── test-probe-runner.php        # Run mechanics + budget abort + lock + health
│       ├── test-uninstall.php           # Table drop + option purge (incl. v0.2 keys)
│       ├── test-model-registry.php      # [v0.2] Migration, CRUD, run-health
│       ├── test-run-lock.php            # [v0.2] Acquire/release/sentinel
│       ├── test-config-version.php      # [v0.2] Hash, bump, version stamp
│       ├── test-projected-cost.php      # [v0.2] probe_count, over_cap, truncation flag
│       └── test-cron-reschedule.php     # [v0.2] Reschedule, idempotent, tick guard
└── .github/workflows/ci.yml        # PHP lint matrix + PHPCS + 300-line check
```

---

## 3. Data flow

```
WP-Cron (scheduled cadence) ─────────────────────────────────────┐
Admin "Run now" (Settings POST + nonce) ──────────────────────────┤
                                                                  ↓
                                              PRV_Run_Lock::acquire()  ← refuse if busy
                                                                  ↓
                                              PRV_Probe_Runner::run()
                                                                  │
                          ┌───────────────────────────────────────┤
                          │  for each peptide × intent × model    │
                          │                                       │
                          │  PRV_Cost_Ledger::can_afford()        │
                          │    └─ ABORT gracefully if at cap      │
                          │                                       │
                          │  PRV_Probe_Provider::probe(query)     │
                          │    └─ PRV_Gateway_Client              │
                          │         └─ Cloudflare AI Gateway      │
                          │              └─ OpenRouter / LLM      │
                          │                                       │
                          │  PRV_Citation_Detector                │
                          │                                       │
                          │  $wpdb->insert(prv_ai_visibility)     │
                          │    └─ config_version column stamped   │
                          │  PRV_Cost_Ledger::update_row_cost()   │
                          └───────────────────────────────────────┘
                                                                  │
                                         PRV_Model_Registry::update_health()
                                         PRV_Run_Lock::release()
                                                                  │
Admin page load ──────────────────────────────────────────────────┤
                                                                  ↓
                                      PRV_Admin_Page::render_page()
                                              │
                                    PRV_Collector_Registry
                                              │
                             PRV_Ai_Visibility_Collector::collect()
                             PRV_Ai_Visibility_Panel::render()
                                              │
                                       Browser (Chart.js)
                                       (config-change annotations via config_versions option)
```

---

## 4. Database

**Table:** `{prefix}prv_ai_visibility`

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | Auto-increment |
| run_id | VARCHAR(36) | UUID per run |
| captured_at | DATETIME | UTC |
| peptide_slug | VARCHAR(200) | e.g. "bpc-157" |
| peptide_label | VARCHAR(200) | e.g. "BPC-157" |
| model | VARCHAR(200) | e.g. "perplexity/sonar" |
| prompt_intent | VARCHAR(200) | Template string |
| cited | TINYINT(1) | 1 = peptiderepo.com in sources |
| our_position | INT NULL | 1-based position; NULL if not cited |
| source_domains | LONGTEXT | JSON array of domains |
| raw_excerpt | LONGTEXT | First 500 chars of LLM response |
| cost_usd | DECIMAL(12,8) | Actual call cost |
| config_version | INT NULL | [v0.2] Config version at run time; NULL for legacy rows |

Schema version: `prv_schema_version` = 2.

**Key options (v0.2.0)**

| Option | Type | Notes |
|--------|------|-------|
| prv_models | array | v2 rich-object model list; migrated from flat-string v1 |
| prv_models_schema_version | int | 2 when migration complete |
| prv_config_versions | array | All config-version records |
| prv_active_config_version | int | Currently active version number |
| prv_cadence | string | 'weekly'\|'daily'\|'twicedaily' |
| prv_api_key_status | string | 'ok'\|'failed'\|'unknown' |
| prv_api_key_last_check | string | Datetime of last status update |
| prv_last_run_at | string | Datetime of last run |
| prv_last_run_counts | array | {probed, skipped_budget, skipped_error, truncated, run_id} |
| prv_last_run_truncated | int | 1 when last run hit the budget cap mid-way |
| prv_last_run_truncated_at | string | Datetime of last truncation event |
| _transient_prv_run_lock | int | Holds Unix timestamp while a run is in progress (TTL 3600s) |

---

## 5. External API integrations

### Cloudflare AI Gateway
All LLM calls route through:
```
https://gateway.ai.cloudflare.com/v1/{PRV_CF_ACCOUNT_ID}/{PRV_CF_GATEWAY_ID}/openrouter
```
Falls back to direct OpenRouter when the constants are absent.

### Providers (v0.1 + v0.2 UI-managed)

| Provider | Model | Citation source |
|----------|-------|-----------------|
| PRV_Perplexity_Provider | `perplexity/sonar` | `citations[]` array |
| PRV_OpenRouter_Provider | any OpenRouter slug | annotations or inline URL regex |

Models are now managed via the Settings UI rather than hardcoded. The `prv_models` option is the source of truth; `PRV_Config::get_models()` delegates to `PRV_Model_Registry::get_enabled_slugs()`.

---

## 6. Collector / Panel seam

Same as v0.1.0 -- see CONTEXT.md for the extension guide.

---

## 7. Security

- Secrets read from `wp-config.php` constants at runtime -- never stored in DB.
- API key status shown in 3 states; key never displayed.
- All admin actions: `manage_options` check + nonce verification.
- All DB values escaped with `$wpdb->prepare()`.
- All HTML output escaped at render.
- AJAX `prv_test_model`: capability + nonce + 30s rate-limit transient.

---

## 8. Key decisions

| Decision | Rationale |
|----------|-----------|
| `prv_models_schema_version` for migration gate | Cheap option check; migration is idempotent and safe to call on every plugins_loaded without performance cost. |
| Config-version as hash + version-number | Hash detects changes; integer version is stable foreign key for run rows and chart annotations. Orphaned rows (NULL config_version or old version) are excluded from current-config scoring. |
| Run-lock as transient | WP transients work across all environments; TTL guards against stale locks from crashed runs; no separate table needed. |
| Projected cost from probe_count × fixed estimate | Conservative overestimate; avoids the need for per-model price config in v1; operator sees a safe upper bound. |
| Three-state API key (not two) | A defined-but-revoked key showed false-green in v1 (QA finding M4). The third state "defined, last run failed" closes that gap without ever displaying the key. |
| Sticky save-bar warning (not just card banner) | Card banner scrolls off; save-bar is always in viewport on a page with a tall model table (QA finding M2). |
