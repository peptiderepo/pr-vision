# Architecture — PR Vision

**Version:** 0.3.0 | **Last updated:** 2026-06-15

---

## 1. Overview

`pr-vision` is an internal WordPress plugin that runs configurable server-side LLM probes to measure whether peptiderepo.com appears in AI citations across core peptide queries. It stores a time-series in a custom table and renders an admin dashboard (trendline + standings) and a full Settings page (model manager + probe config).

**v1 scope:** AI-visibility only. The collector/panel seam is present so future data categories can be added as new classes.
**v2 additions:** Model manager CRUD (no deploy needed), config-version tracking, run-lock, projected cost, API-key three-state, dark "Assay" Settings UI.
**v0.3.0 additions:** Per-call cost/metadata + I/O audit trail; Costs + Call Log admin pages; non-modal detail drawer; daily I/O prune cron; `PRV_Gateway_Client` exception body removed (P0).

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
│   │   ├── class-prv-model-test-ajax.php     # [v0.2] Rate-limited AJAX handler for Test button
│   │   ├── class-prv-key-store.php           # [v0.2.3] API key resolver: constant → enc-option → none
│   │   ├── class-prv-crypto-helper.php       # [v0.2.3] libsodium/OpenSSL encrypt+decrypt
│   │   ├── class-prv-key-manager-renderer.php # [v0.2.3] Write-only key card renderer
│   │   └── class-prv-key-test-ajax.php       # [v0.2.3] AJAX handler for Test key button
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
│       ├── class-prv-ai-visibility-panel.php  # [v0.2.1] Orchestrates render; delegates to PRV_Dashboard_Renderer
│       └── class-prv-dashboard-renderer.php     # [v0.2.1] Bento tiles (run-health pill) + trendline config-change markers
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
├── .github/workflows/ci.yml        # PHP lint matrix + PHPCS + 300-line check
└── .github/workflows/deploy.yml    # [v0.2.1] Standalone rsync deploy to Hostinger PROD (CI gated)
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

### v0.3.0 additions

**Table:** `{prefix}prv_call_meta` (kept indefinitely)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | Auto-increment |
| visibility_row | BIGINT UNSIGNED NULL | FK to prv_ai_visibility.id (nullable) |
| run_id | VARCHAR(36) | UUID for the run |
| peptide_slug | VARCHAR(200) | e.g. "bpc-157" |
| model | VARCHAR(200) | e.g. "perplexity/sonar" |
| intent_label | VARCHAR(200) | Prompt intent template |
| tokens_in | INT NULL | Input token count from API |
| tokens_out | INT NULL | Output token count from API |
| cost_usd | DECIMAL(12,8) | Actual call cost |
| latency_ms | INT NULL | Call latency in ms |
| cited | TINYINT(1) NULL | 1=cited, 0=not cited, NULL=error |
| http_status | INT | HTTP status (200=ok, 4xx/5xx=error) |
| captured_at | DATETIME | UTC |
| config_version | INT NULL | Config version at run time |
| io_captured | TINYINT(1) | 1 when write_io() succeeded; 0 for error rows and legacy/pre-feature rows; drives drawer state (0=Legacy, 1+io present=Normal, 1+io absent=Pruned) |

**Table:** `{prefix}prv_call_io` (pruned after retention window)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | Auto-increment |
| call_id | BIGINT UNSIGNED | FK to prv_call_meta.id |
| prompt_text | LONGTEXT | Rendered prompt (plain text) |
| response_text | LONGTEXT | Raw LLM response text |
| captured_at | DATETIME | UTC (indexed for prune query) |

---

## 4a. Original Database

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
| prv_api_key_status | string | 'ok'|'failed'|'unknown' |
| prv_provider_key_enc | string | [v0.2.3] Encrypted API key ciphertext (hex(nonce):hex(ciphertext)); PRV_Key_Store |
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

- **API key resolver (v0.2.3):** `PRV_Key_Store::get_key()` is the single access point for
  the API key. Precedence: `PRV_OPENROUTER_API_KEY` constant → encrypted option `prv_provider_key_enc` → none.
  All probe + test paths route through this resolver; direct constant reads are removed.
- **Encrypted at rest (v0.2.3):** admin-entered key stored as ciphertext via `PRV_Crypto_Helper`
  (libsodium preferred, OpenSSL AES-256-GCM fallback). Encryption key derived from WP salts via SHA-256.
  Plaintext never stored in any option, transient, log, or error message.
- **Write-only UI (v0.2.3):** Settings shows source status only; password input always renders empty.
  Key value never in page source, JS, REST responses, or AJAX responses.
- **wp-config precedence:** constant takes priority; admin option is only used when constant is absent.
  Input is disabled with a note when constant is defined.
- Secrets read from `wp-config.php` constants at runtime (pre-v0.2.3 primary path).
- All admin actions: `manage_options` check + nonce verification.
- All DB values escaped with `$wpdb->prepare()`.
- All HTML output escaped at render.
- AJAX `prv_test_model`: capability + nonce + 30s rate-limit transient.
- AJAX `prv_test_key`: capability + nonce + 30s rate-limit transient; key never in response.

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
| wp-config constant beats admin option | Most-secure path (constant) stays the default and is untouched by the admin UI. Admin option is a convenience fallback, never a replacement. |
| libsodium preferred, OpenSSL fallback | Sodium is bundled in PHP 7.2+ (available on all supported hosts); OpenSSL AES-256-GCM is the universal fallback. Algorithm inferred from nonce length at decrypt time, no stored metadata needed. |
| Key resolver in PRV_Key_Store, not in providers | Single source of truth — any future provider added to the plugin automatically gets the right key without repeating the precedence logic. |
| PRV_Crypto_Helper split from PRV_Key_Store | Keeps each file under the 300-line AI-readability limit while preserving a deep-module interface: Key_Store owns the WP-layer (options, salts, source detection); Crypto_Helper owns the crypto primitives. |
