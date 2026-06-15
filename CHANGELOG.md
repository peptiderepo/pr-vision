# Changelog — PR Vision

All notable changes to this project will be documented in this file.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
Versioning: [Semantic Versioning](https://semver.org/)

---

## [0.2.3] — 2026-06-15

UI-editable provider API key: encrypted at rest, write-only, wp-config precedence.

### Added

- **`PRV_Key_Store`** (`includes/core/class-prv-key-store.php`): single key resolver with
  precedence chain — `PRV_OPENROUTER_API_KEY` constant → encrypted admin option `prv_provider_key_enc` → none.
  `get_key()` is the sole key-access point for all probe and test paths.
- **`PRV_Crypto_Helper`** (`includes/core/class-prv-crypto-helper.php`): low-level
  symmetric encryption/decryption. Prefers libsodium `sodium_crypto_secretbox`
  (XSalsa20-Poly1305); falls back to OpenSSL AES-256-GCM. Encryption key is derived
  from WP salts (`AUTH_KEY` + `SECURE_AUTH_KEY`) via SHA-256 — environment-specific,
  never stored. Storage format: `hex(nonce):hex(ciphertext)`.
- **`PRV_Key_Manager_Renderer`** (`includes/core/class-prv-key-manager-renderer.php`):
  renders the write-only "Provider API Key" card in Settings. Shows source status only
  ("Set via wp-config (takes precedence)" / "Set via admin" / "Not set") plus last-run
  health. Password input always renders empty. Constant defined → input disabled with note.
- **`PRV_Key_Test_Ajax`** (`includes/core/class-prv-key-test-ajax.php`): AJAX handler for
  the "Test key" button. Resolves key via `PRV_Key_Store::get_key()`, runs one cheap probe,
  reports valid/invalid with `aria-live` result; key never in response.
- **Key set/remove POST actions** in `PRV_Settings_Controller`:
  `handle_key_set()` (encrypts + stores; empty input = no-op) and `handle_key_remove()`.
- **`prv_key_set`, `prv_key_remove`, `prv_key_error`** notices in `PRV_Settings_Renderer`.
- **`wp_ajax_prv_test_key`** hook registered in `PRV_Settings_Page` → dispatches to `PRV_Key_Test_Ajax`.
- **Tests** (`tests/unit/test-key-store.php`): encrypt↔decrypt round-trip, wrong-key fails
  silently, resolver precedence (constant > option > none), write-only render emits no key,
  Remove clears option, constant takes precedence.

### Changed

- **`PRV_OpenRouter_Provider`**: removed direct `PRV_OPENROUTER_API_KEY` constant reads;
  `probe()` now calls `PRV_Key_Store::get_key()`. New `probe_with_key(string $q, string $key)`
  method for the test path (PRV_Key_Test_Ajax injects the resolved key). `is_configured()`
  now checks `PRV_Key_Store::get_key() !== ''`.
- **`PRV_Perplexity_Provider`**: same key-resolver migration — `probe()` calls `PRV_Key_Store::get_key()`.
  `is_configured()` checks the store. Direct constant reads removed.
- **`PRV_Model_Test_Ajax`**: resolves key via `PRV_Key_Store::get_key()` instead of checking
  the constant directly. Returns a specific "key not configured — set via Settings" message
  when no key is available.
- **`PRV_Settings_Renderer`**: replaced read-only `render_api_key_status()` with a call to
  `PRV_Key_Manager_Renderer::render()`. Added key-action notices. Provider API Key card is
  now separate from the Probe Configuration form.
- **`PRV_Settings_Page`**: added `NONCE_KEY` + `NONCE_KEY_TEST` constants; registered
  `admin_post_prv_key_set`, `admin_post_prv_key_remove`, `wp_ajax_prv_test_key`.
- **`uninstall.php`**: updated comment to explicitly list `prv_provider_key_enc` as a v0.2.3
  addition purged by the `prv_` wildcard DELETE.
- **`tests/unit/test-uninstall.php`**: seeds + asserts `prv_provider_key_enc` purge.

### Security

- Plaintext API key never stored in any option, transient, log, or echoed to the browser.
- The encrypted option (`prv_provider_key_enc`) is ciphertext only; decryption is server-side
  at probe time only. The encryption key is derived from WP salts — not stored.
- `manage_options` + nonce required for all key actions (set, remove, test).
- `wp-config` constant always takes precedence; admin path is only active when absent.

---

## [0.2.2] — 2026-06-14

Kill white WP admin gutters around the dark "Assay" UI — CSS only, no logic change.

### Fixed

- **Admin chrome (dashboard + settings):** `#wpcontent`, `#wpbody`, and `#wpbody-content` now receive the dark page background (`#14181C`) on both PR Vision screens. WP's default `20px` left padding on `#wpcontent` is removed so no light gutter appears between the left menu and the dark content area.
- **WP admin bar / first-element gap:** `#wpbody` dark fill removes the white strip between the admin bar and the first card.
- **Page `<h1>` header:** `.wrap > h1` colour set to the light text token (`#EEF2F5`) so the dashboard heading renders on dark, not white.
- **Proxy-note banner (dashboard):** `.notice.prv-proxy-note` overridden to dark surface (`#1C2228`) with teal left border — banner now sits on dark bg matching the surrounding cards.
- **Scope:** entire CSS block delivered via `wp_add_inline_style('wp-admin', …)` inside `PRV_Admin_Page::enqueue_assets()`, which is already guarded by `strpos($hook, 'pr-vision')`. Styles are absent on every other WP admin page. WP admin bar and left menu are not touched.

---

## [0.1.1] — 2026-06-13

Rename to PR Vision; fix www-strip in competitor display; PHPCS gating; remove dead cache.

### Changed

- **Rename:** plugin, repo, slug, class prefix, option/hook/table prefix all updated from `pgm_`/`PGM_` to `prv_`/`PRV_` and from `peptide-geo-monitor`/`GEO Monitor` to `pr-vision`/`PR Vision`. No data migration required (no `pgm_*` data existed).

### Fixed

- **P2-A:** `ltrim($host, 'www.')` character-mask bug in `PRV_Citation_Detector::parse_domains()` replaced with `str_starts_with($host, 'www.') ? substr($host, 4) : $host`. Domains starting with `w` (e.g. `wikipedia.org`, `webmd.com`) are no longer corrupted in competitor standings.
- **P2-C:** Removed dead in-run cache from `PRV_Probe_Runner::run()`. The `peptides × intents × models` loop visits each `(slug, intent, model)` triplet exactly once, so the cache could never produce a hit on a single traversal.

### CI

- **P2-B:** Dropped `continue-on-error: true` from the PHPCS step — WordPress Coding Standards now gates the build. PHPCS was already green on v0.1.0; this enforces the DoD for future commits.
