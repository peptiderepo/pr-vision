# Conventions — Peptide GEO Monitor

**Version:** 0.1.0 | **Last updated:** 2026-06-13

Applies to all contributors and AI agents working in this repo. Where this document and `AGENT-OPERATING-STANDARD.md` conflict on process, the Standard wins; this document wins on code style.

---

## 1. PHP style

- **Standard:** WordPress Coding Standards (PHPCS `ruleset ref="WordPress"`).
- **Declare:** `declare(strict_types=1);` at the top of every PHP file.
- **Formatting:** Tabs for indentation (WP standard).

## 2. Naming

| Concept | Convention | Example |
|---------|-----------|---------|
| Class | `PGM_` prefix + PascalCase | `PGM_Cost_Ledger` |
| Interface | `PGM_` prefix + PascalCase | `PGM_Probe_Provider` |
| File | `class-pgm-*.php` or `interface-pgm-*.php` | `class-pgm-probe-runner.php` |
| Option | `pgm_` prefix | `pgm_monthly_budget_usd` |
| Hook / cron | `pgm_` prefix | `pgm_weekly_probe` |
| Constant | `PGM_` prefix | `PGM_TARGET_DOMAIN` |
| wp-config key | `PGM_` prefix | `PGM_OPENROUTER_API_KEY` |
| Test file | `test-*.php` | `test-citation-detector.php` |

## 3. File structure

- **One class per file.** Interfaces get their own file.
- **300-line limit** (enforced by CI). Split at the method boundary when approaching the limit.
- Autoloader resolves `PGM_Foo_Bar` → `class-pgm-foo-bar.php` via a search across `includes/` subdirectories.

## 4. Docblocks

Every class must have a preamble docblock:
```
/**
 * <One-sentence what>.
 *
 * Who triggers: <which class/hook calls this>.
 * Dependencies: <what this class needs to exist>.
 *
 * @see <cross-reference 1>
 * @see <cross-reference 2>
 * @package PeptideGeoMonitor
 */
```

Every public method must have:
- `@param` with type + description.
- `@return` with type.
- A sentence listing side effects (DB writes, HTTP calls, option writes).

## 5. Security rules

- **Sanitize** all input at the boundary (`sanitize_text_field()`, `absint()`, `wp_unslash()`).
- **Escape** all output at render (`esc_html()`, `esc_url()`, `wp_json_encode()`, `wp_kses_post()`).
- **Nonce** every admin form action.
- **Capability** check (`manage_options`) every admin action handler.
- **No secrets in code** — read from wp-config constants at runtime.

## 6. Tests

- Tests live in `tests/unit/test-*.php` and are run by the CI `lint-php` job via `php $test`.
- Each test file `require_once`s `tests/bootstrap.php` and calls `exit( pgm_test_summary() )`.
- Use `pgm_assert()`, `pgm_assert_equals()`, `pgm_assert_throws()`.
- Mock HTTP via `$GLOBALS['pgm_test_state']['remote_posts']` (queued responses).
- No PHPUnit dependency.

## 7. Adding a new provider

1. Create `includes/providers/class-pgm-{name}-provider.php`.
2. Implement `PGM_Probe_Provider` (`probe()`, `get_name()`, `is_configured()`).
3. Add a branch in `PGM_Probe_Runner::resolve_provider()`.
4. Update `ARCHITECTURE.md` §5 (Providers table).
5. Add a parse test in `tests/unit/test-provider-parsing.php`.

## 8. Adding a new collector/panel pair

1. Implement `PGM_Data_Collector` in `includes/collector/`.
2. Implement `PGM_Dashboard_Panel` in `includes/panel/`.
3. Register both in `PGM_Plugin::init()` via `PGM_Collector_Registry::instance()`.
4. Update `ARCHITECTURE.md` §6 (Collector/Panel seam).
