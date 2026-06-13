# Conventions — PR Vision

**Version:** 0.1.1 | **Last updated:** 2026-06-13

Applies to all contributors and AI agents working in this repo. Where this document and `AGENT-OPERATING-STANDARD.md` conflict on process, the Standard wins; this document wins on code style.

---

## 1. PHP style

- **Standard:** WordPress Coding Standards (PHPCS `ruleset ref="WordPress"`).
- **Declare:** `declare(strict_types=1);` at the top of every PHP file.
- **Formatting:** Tabs for indentation (WP standard).

## 2. Naming

| Concept | Convention | Example |
|---------|-----------|---------|
| Class | `PRV_` prefix + PascalCase | `PRV_Cost_Ledger` |
| Interface | `PRV_` prefix + PascalCase | `PRV_Probe_Provider` |
| File | `class-prv-*.php` or `interface-prv-*.php` | `class-prv-probe-runner.php` |
| Option | `prv_` prefix | `prv_monthly_budget_usd` |
| Hook / cron | `prv_` prefix | `prv_weekly_probe` |
| Constant | `PRV_` prefix | `PRV_TARGET_DOMAIN` |
| wp-config key | `PRV_` prefix | `PRV_OPENROUTER_API_KEY` |
| Test file | `test-*.php` | `test-citation-detector.php` |

## 3. File structure

- **One class per file.** Interfaces get their own file.
- **300-line limit** (enforced by CI). Split at the method boundary when approaching the limit.
- Autoloader resolves `PRV_Foo_Bar` → `class-prv-foo-bar.php` via a search across `includes/` subdirectories.

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
 * @package PrVision
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
- Each test file `require_once`s `tests/bootstrap.php` and calls `exit( prv_test_summary() )`.
- Use `prv_assert()`, `prv_assert_equals()`, `prv_assert_throws()`.
- Mock HTTP via `$GLOBALS['prv_test_state']['remote_posts']` (queued responses).
- No PHPUnit dependency.

## 7. Adding a new provider

1. Create `includes/providers/class-prv-{name}-provider.php`.
2. Implement `PRV_Probe_Provider` (`probe()`, `get_name()`, `is_configured()`).
3. Add a branch in `PRV_Probe_Runner::resolve_provider()`.
4. Update `ARCHITECTURE.md` §5 (Providers table).
5. Add a parse test in `tests/unit/test-provider-parsing.php`.

## 8. Adding a new collector/panel pair

1. Implement `PRV_Data_Collector` in `includes/collector/`.
2. Implement `PRV_Dashboard_Panel` in `includes/panel/`.
3. Register both in `PRV_Plugin::init()` via `PRV_Collector_Registry::instance()`.
4. Update `ARCHITECTURE.md` §6 (Collector/Panel seam).
