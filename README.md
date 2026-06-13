# Peptide GEO Monitor

An internal WordPress plugin that runs weekly server-side LLM probes to measure whether peptiderepo.com is cited by AI assistants across core peptides.

**v0.1.0 — AI-visibility dashboard only. Internal use; no public-facing output.**

---

## Purpose

GEO (Generative Engine Optimization) is peptiderepo.com's #1 strategic goal. This plugin provides the measurement instrument: weekly LLM probes → time-series storage → admin trendline + per-peptide standings.

The displayed metric is a **directional proxy** — API probes do not replicate the consumer ChatGPT/Gemini experience (different retrieval, personalisation, and system prompts), but they provide a consistent, trackable signal.

---

## Required wp-config.php constants

```php
// OpenRouter API key (sk-or-…) — required for all providers.
define( 'PGM_OPENROUTER_API_KEY', 'sk-or-YOUR_KEY_HERE' );

// Cloudflare AI Gateway routing (optional — falls back to direct OpenRouter).
define( 'PGM_CF_ACCOUNT_ID', 'YOUR_CF_ACCOUNT_ID' );
define( 'PGM_CF_GATEWAY_ID', 'YOUR_GATEWAY_NAME' );
```

**Never commit these values. They are read at runtime from wp-config.php only.**

---

## Architecture

See `ARCHITECTURE.md` for the full file tree, data flow, and design decisions.

---

## Admin page

Navigate to **GEO Monitor** in the WordPress admin sidebar (`manage_options` required).

The page shows:
- A proxy-metric disclaimer note.
- A Chart.js trendline of the visibility score across runs.
- A per-peptide standings table (cited?, our position, top competitor domains).
- Month-to-date cost vs. the monthly cap.
- A **"Run now"** button to trigger an on-demand probe run.

---

## Scheduling

A weekly WP-Cron event (`pgm_weekly_probe`) is registered on plugin activation and cleared on deactivation. Probe runs (cron or manual) respect the hard monthly budget cap.

---

## Cost cap

Default cap: **$5.00 USD/month** (configurable via the `pgm_monthly_budget_usd` option). The runner checks the month-to-date spend **before** each API call and stops gracefully when the cap is reached — partial runs are recorded, never over-spend.

---

## Uninstall

Uninstalling the plugin drops the `{prefix}pgm_ai_visibility` table and deletes all `pgm_*` options. Deactivation only clears the cron schedule.

---

## Developer notes

- PHP 8.1+ required. No runtime Composer dependencies.
- All classes prefixed `PGM_`. All options/hooks prefixed `pgm_`.
- Files are kept under 300 lines; one class per file.
- See `CONVENTIONS.md` for naming patterns and extension guides.
- See `CONTEXT.md` for the domain glossary including the visibility score formula.
