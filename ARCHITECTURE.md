# Architecture — PR Vision

**Version:** 0.1.1 | **Last updated:** 2026-06-13

---

## 1. Overview

`pr-vision` is an internal WordPress plugin that runs weekly server-side LLM probes to measure whether peptiderepo.com appears in AI citations across core peptide queries. It stores a time-series in a custom table and renders an admin dashboard.

**v1 scope:** AI-visibility only. The collector/panel seam is present so future data categories (keyword rankings, technical-SEO) can be added as new classes.

---

## 2. File tree

```
pr-vision/
├── pr-vision.php          # Plugin boot: constants, autoloader, hooks
├── uninstall.php                    # Full data teardown: DROP TABLE + DELETE prv_* options
├── composer.json                    # Dev deps (PHPCS only)
├── phpcs.xml.dist                   # PHPCS config (WordPress standard)
├── includes/
│   ├── core/
│   │   ├── class-prv-autoloader.php          # SPL autoloader: PRV_* → includes/**
│   │   ├── class-prv-plugin.php              # Orchestrator: boots sub-systems
│   │   ├── class-prv-activator.php           # Activation: table + defaults + cron
│   │   ├── class-prv-deactivator.php         # Deactivation: clear cron
│   │   ├── class-prv-table-manager.php       # dbDelta create/drop + get_table_name()
│   │   ├── class-prv-config.php              # Typed getters + seed_defaults()
│   │   ├── class-prv-cron.php                # Weekly WP-Cron schedule/clear
│   │   ├── class-prv-cost-ledger.php         # MTD cost + hard monthly cap enforcement
│   │   ├── class-prv-probe-result.php        # Immutable value object from a probe call
│   │   ├── class-prv-probe-runner.php        # Orchestrates peptide×intent×model run
│   │   ├── class-prv-collector-registry.php  # Singleton registry for collectors + panels
│   │   └── class-prv-admin-page.php          # Admin page: menu, Run now, rendering
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
│       ├── test-probe-runner.php        # Run mechanics + budget abort
│       └── test-uninstall.php           # Table drop + option purge
└── .github/workflows/ci.yml        # PHP lint matrix + PHPCS + 300-line check
```

---

## 3. Data flow

```
WP-Cron (weekly) ──────────────────────────────────────┐
Admin "Run now" (POST + nonce) ─────────────────────────┤
                                                        ↓
                                            PRV_Probe_Runner::run()
                                                        │
                    ┌───────────────────────────────────┤
                    │  for each peptide × intent × model│
                    │                                   │
                    │  PRV_Cost_Ledger::can_afford()     │
                    │    └─ ABORT gracefully if at cap   │
                    │                                   │
                    │  PRV_Probe_Provider::probe(query)  │
                    │    └─ PRV_Gateway_Client           │
                    │         └─ Cloudflare AI Gateway   │
                    │              └─ OpenRouter         │
                    │                   └─ LLM           │
                    │                                   │
                    │  PRV_Citation_Detector             │
                    │    └─ extract domains              │
                    │    └─ detect peptiderepo.com       │
                    │                                   │
                    │  $wpdb→insert(prv_ai_visibility)   │
                    │  PRV_Cost_Ledger::update_row_cost()│
                    └───────────────────────────────────┘
                                                        │
Admin page load ────────────────────────────────────────┤
                                                        ↓
                                  PRV_Admin_Page::render_page()
                                          │
                                PRV_Collector_Registry
                                          │
                         PRV_Ai_Visibility_Collector::collect()
                                          │  (DB reads)
                         PRV_Ai_Visibility_Panel::render()
                                          │  (HTML output)
                                   Browser (Chart.js)
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

Schema version tracked in `prv_schema_version` option.

---

## 5. External API integrations

### Cloudflare AI Gateway

All LLM calls route through:
```
https://gateway.ai.cloudflare.com/v1/{PRV_CF_ACCOUNT_ID}/{PRV_CF_GATEWAY_ID}/openrouter
```

Falls back to direct OpenRouter (`https://openrouter.ai/api/v1`) when the constants are absent or empty.

Pattern mirrors PRAutoBlogger's `class-open-router-config.php` and `class-open-router-request-builder.php` — same auth injection, same cURL belt-and-suspenders for Hostinger.

### Providers (v1)

| Provider | Model | Citation source |
|----------|-------|-----------------|
| PRV_Perplexity_Provider | `perplexity/sonar` | `citations[]` array (primary, real-web retrieval) |
| PRV_OpenRouter_Provider | `openai/gpt-4o-search-preview` | annotations or inline URL regex |
| PRV_OpenRouter_Provider | `google/gemini-2.0-flash-001` | annotations or inline URL regex |

---

## 6. Collector / Panel seam

```
PRV_Data_Collector (interface)           PRV_Dashboard_Panel (interface)
        │                                          │
PRV_Ai_Visibility_Collector          PRV_Ai_Visibility_Panel
        │                                          │
        └──────── PRV_Collector_Registry ──────────┘
                  (key: "ai_visibility")
```

To add a future SEO collector (keyword rankings, schema coverage…):
1. Implement `PRV_Data_Collector` + `PRV_Dashboard_Panel`.
2. Register both in `PRV_Plugin::init()`.
No dashboard shell changes required.

---

## 7. Security

- Secrets read from `wp-config.php` constants at runtime — never hardcoded.
- All admin actions: `manage_options` capability check + nonce verification.
- All DB values escaped with `$wpdb->prepare()`.
- All HTML output escaped with `esc_html()`, `esc_url()`, `wp_json_encode()`.

---

## 8. Key decisions

| Decision | Rationale |
|----------|-----------|
| Use OpenRouter for all models | Single API key, single gateway URL, Perplexity sonar is accessible via OpenRouter's model routing. |
| Perplexity sonar as primary | Returns an explicit `citations[]` array — the most faithful citation signal available. |
| Cost ledger from visibility table | No separate ledger table needed; `SUM(cost_usd)` per calendar month is sufficient and keeps the schema minimal. |
| Chart.js via CDN | No build step, no bundler, admin-only load, pinned version. |
| Plain PHP tests (no PHPUnit) | Mirrors peptide-repo-core's pattern; CI runs in the lint-php job with no extra setup. |
