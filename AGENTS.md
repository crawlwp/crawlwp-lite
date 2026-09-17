# AGENTS.md — CrawlWP SEO Plugin (mihdan-index-now)

## Project Overview

**CrawlWP SEO – Instant Indexing & SEO Insights** is a WordPress plugin that provides IndexNow-based instant indexing and a comprehensive on-page SEO metabox. The plugin slug is `mihdan-index-now`, the internal namespace is `Mihdan\IndexNow`, and the brand name is **CrawlWP**.

- **Main plugin file:** `mihdan-index-now.php`
- **Minimum PHP:** 8.0
- **Minimum WordPress:** 6.0
- **Text domain:** `mihdan-index-now`
- **Pro add-on:** `mihdan-index-now-pro` — detected at runtime via `defined('CRAWLWP_PRO_VERSION')`

---

## Architecture & File Structure

```
src/
├── SEOCore/
│   ├── SEOCoreInit.php              # Bootstraps all SEOCore sub-modules
│   ├── CoreSettings.php             # Plugin-wide SEO settings
│   ├── GetInstanceTrait.php         # Singleton trait used by init classes
│   ├── MetaBox/
│   │   ├── MetaBox.php              # Registers WP metabox via add_meta_box()
│   │   ├── MetaFields.php           # Field constants, save/load with nonce + sanitization
│   │   ├── Assets.php               # Enqueues CSS/JS, wp_localize_script, AJAX endpoints
│   │   ├── FrontendHead.php         # Outputs <title>, meta tags, OG, schema on wp_head
│   │   ├── views/
│   │   │   └── metabox-template.php # Full PHP template (tabs: General, Analysis, Social, Schema, Links, Advanced)
│   │   └── assets/
│   │       ├── crawlwp-metabox.css  # All metabox styles
│   │       └── crawlwp-metabox.js   # CrawlWP JS object — event-driven, jQuery-based
│   └── SiteVerification/
│       ├── SiteVerificationSettings.php
│       └── SiteVerificationFrontendOutput.php
```

### Autoloading

PSR-4 autoloading is configured in `vendor-prefixed/composer/autoload_psr4.php`, mapping `Mihdan\IndexNow\` → `src/`. All new classes must follow this namespace convention.

---

## Coding Conventions

### PHP

- **PHP 8.0+ features:** Use typed properties, union types, named arguments, `match`, `readonly` where appropriate. All files must declare strict namespace.
- **Namespace:** `Mihdan\IndexNow\SEOCore\MetaBox` for metabox classes.
- **Constants for meta keys:** All post meta keys are defined as `public const` in `MetaFields.php`. Never use raw strings for meta keys — always reference the constant (e.g., `MetaFields::SEO_TITLE`).
- **Nonce verification:** Every form save and AJAX endpoint must verify nonce (`wp_verify_nonce`) and capability (`current_user_can`).
- **Sanitization:** All input is sanitized via `sanitize_text_field()`, `absint()`, or `array_map('sanitize_text_field', ...)` before saving.
- **Escaping:** All output in templates uses `esc_html()`, `esc_attr()`, `esc_url()`, or `wp_kses_post()`.
- **Text domain:** Use `'mihdan-index-now'` for all `__()`, `esc_html_e()`, `esc_attr_e()` calls. Include translator comments for strings with placeholders.
- **AJAX endpoints:** Registered in `Assets.php` via `wp_ajax_{action}`. Use `wp_send_json_success()`/`wp_send_json_error()`. Provide filter hooks (e.g., `crawlwp_ai_generate_seo`, `crawlwp_insights_data`) for pro plugin extensibility.

### JavaScript (`crawlwp-metabox.js`)

- **Structure:** Single plain JS object literal (`CrawlWP = { ... }`) exposed as `window.CrawlWPMetabox`. **Do NOT use ES6 class syntax.**
- **jQuery:** All DOM manipulation uses jQuery (`$`). Prefix jQuery-wrapped variables with `$` (e.g., `self.$title`).
- **ES5 compatible:** No arrow functions, no `const`/`let` (use `var`), no template literals, no optional chaining (`?.`), no nullish coalescing (`??`). The plugin supports older browsers.
- **Event bus:** Use custom events (`crawlwp:analyze`, `crawlwp:renderLinks`, `crawlwp:sync`, `crawlwp:measure`) via `self.emit(name)` instead of calling methods directly. Register listeners in `bindEventBus()`.
- **Editor compatibility:** Must support both Classic Editor and Gutenberg (Block Editor).
  - Guard all `wp.data.select('core/editor')` calls — it returns `undefined` in the Classic Editor.
  - The Gutenberg content canvas is iframed; use `wp.data.select('core/editor').getEditedPostContent()` instead of DOM scraping.
  - Wrap initialization in `$(function(){ ... })` DOM-ready handler.
- **i18n:** All user-facing strings come from `crawlwpSEO.i18n.*` (passed via `wp_localize_script`). Use `self.fmt()` for placeholder substitution (supports `%s`, `%d`, `%1$s` style).
- **Placeholders/tokens:** Use double curly bracket format: `{{title}}`, `{{sep}}`, `{{sitename}}`, etc. Never use `%%token%%`.

### CSS (`crawlwp-metabox.css`)

- **Prefix:** All classes use `cwp-` prefix (e.g., `.cwp-input`, `.cwp-tab`, `.cwp-chip`).
- **No CSS frameworks.** All styles are custom.
- **State classes:** Use `.is-*` pattern for states (e.g., `.is-active`, `.is-good`, `.is-warn`, `.is-bad`, `.is-loading`, `.is-copied`).
- **Hidden elements:** Use `style="display:none"` instead of the HTML `hidden` attribute, because CSS `display:flex` rules override `hidden`.

### Templates (`metabox-template.php`)

- PHP template renders all tabs/panels server-side. Dynamic content is populated by JS on the client.
- Tab order: General → Analysis → Social → Schema → Links → Advanced.
- Pro-only features (e.g., Insights tab) are wrapped in `if (defined('CRAWLWP_PRO_VERSION'))`.

---

## Metabox Feature Set

### Tabs & Capabilities

| Tab | Features |
|-----|----------|
| **General** | SEO title + description with variable inserters, URL slug, live search preview (desktop/mobile), readability badge, AI generate buttons |
| **Analysis** | Focus keyword field, duplicate keyword warning, 16 on-page SEO checks with score ring, content change detection |
| **Social** | Facebook OG (title, description, image) and X/Twitter card settings, image dimension validation, featured image fallback |
| **Schema** | Content type, headline, breadcrumb label + preview, article section, JSON-LD toggle |
| **Links** | Suggested links (with copy URL), outbound links, inbound links — all with "Show all" for 6+ items |
| **Advanced** | Robots directives (index/follow/advanced), canonical URL, max snippet/image preview, redirect manager (301/302/307/410), Submit to IndexNow button |
| **Insights** *(pro only)* | Multi-engine stats (Google/Bing/Yandex), clicks/impressions/position/CTR cards, keywords table, index status, period selector |

### Variable Tokens

Available in SEO title, meta description, OG title/description, and X title/description fields:

| Token | Resolves To |
|-------|-------------|
| `{{title}}` | Post title |
| `{{sep}}` | Separator character (default `–`) |
| `{{sitename}}` | Site name |
| `{{excerpt}}` | Post excerpt |
| `{{date}}` | Publish date |
| `{{author}}` | Post author display name |
| `{{category}}` | First category |

### Default Behavior

- If SEO title field is empty, assume `{{title}} {{sep}} {{sitename}}` for length checks and preview.
- Score ring appears in the WordPress metabox title bar (not a duplicate custom header).

---

## Pro Plugin Integration

The pro add-on (`mihdan-index-now-pro`) integrates via:

1. **Detection:** `defined('CRAWLWP_PRO_VERSION')` — controls visibility of Insights tab and pro features.
2. **JS flag:** `crawlwpSEO.isProActive` (boolean, passed via `wp_localize_script`).
3. **Filter hooks:**
   - `crawlwp_insights_data` — pro plugin populates real search console data.
   - `crawlwp_ai_generate_seo` — pro plugin provides real AI API integration (fallback generates text from content).
4. **Custom events:** `crawlwp:loadInsights` / `crawlwp:insightsData` for JS-level communication.

---

## AJAX Endpoints

All endpoints require nonce verification and `edit_post` capability.

| Action | Purpose | Nonce Key |
|--------|---------|-----------|
| `crawlwp_load_insights` | Load insights data (per engine/period) | `insightsNonce` |
| `crawlwp_ai_generate` | AI-generate SEO title or description | `aiNonce` |
| `crawlwp_check_duplicate_keyword` | Check if focus keyword is used elsewhere | `kwCheckNonce` |
| `crawlwp_submit_indexnow` | Submit post URL to IndexNow | `indexNowNonce` |

---

## Frontend Output (`FrontendHead.php`)

Outputs on `wp_head` (priority 1):
- `<title>` tag with token resolution
- `<meta name="description">`
- `<meta name="robots">` (index/follow + advanced directives)
- `<link rel="canonical">`
- Open Graph tags (`og:title`, `og:description`, `og:image`, `og:url`, `og:type`, `og:site_name`)
- Twitter Card tags (`twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`, `twitter:creator`)
- JSON-LD structured data (Article/BlogPosting schema)
- Redirect handling on `template_redirect` hook (301/302/307/410)

---

## Common Pitfalls & Lessons Learned

1. **Classic vs Gutenberg guards:** `wp.data` is loaded in Classic Editor (via React) but `wp.data.select('core/editor')` returns `undefined`. Always check: `if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe && wp.data.select('core/editor'))`.

2. **Gutenberg iframed content:** The block editor content canvas is iframed. `$('.block-editor-block-list__layout')` and `$('#content')` return empty. Use `wp.data.select('core/editor').getEditedPostContent()`.

3. **HTML `hidden` attribute vs CSS:** Never rely on the `hidden` attribute when the element has a CSS `display` rule (e.g., `display:flex`) — CSS overrides `hidden`. Use `style="display:none"` and jQuery `.hide()`/`.css('display','flex')`.

4. **MetaFields constant naming:** The class is `MetaFields` (plural), the focus keyword constant is `FOCUS_KEYWORD` (not `KEYWORD`). Double-check constant names when referencing.

5. **MutationObserver in Classic Editor:** The permalink `#editable-post-name` is a `<span>` — no jQuery event covers text content changes. `MutationObserver` is the correct tool for watching it.

6. **Readability must update without keyword:** The `runAnalysis()` method has an early return when no focus keyword is set. `updateReadability()` must be called *before* that gate so readability scoring always reflects current content.

7. **Social image fallback chain:** When removing an X/Twitter image, fall back to OG image. When no OG image, fall back to featured image. When no featured image, show solid green background.

8. **PHP sprintf vs JS fmt():** The `fmt()` helper in JS must handle both positional (`%s`) and numbered (`%1$s`) PHP-style placeholders since i18n strings come from PHP's `__()`.

---

## Dev Tooling

Dev dependencies live in `require-dev` (PHPUnit, PHPStan + `szepeviktor/phpstan-wordpress`, PHPCS/WPCS). After `composer install`:

- `composer test` — runs the zero-dependency smoke script (`tests/TokenMapperTest.php`) and the PHPUnit suite in `tests/unit/`.
- `composer analyse` — PHPStan level 5 over `src/` (`phpstan.neon`, baseline in `phpstan-baseline.neon`; keep the baseline shrinking).
- `composer lint` — PHPCS.

`tests/bootstrap.php` stubs the handful of WordPress helpers the pure classes call (`TokenMapper`, `Schema\Graph`, `InternalLinks\AutoLinker`), so those can be unit tested without a WordPress install. New logic that does not need WordPress should be testable the same way.

`plugin-build.php` aborts the release build when a debug helper (`ray`, `dd`, `dump`, `var_dump`, …) is found anywhere under `src/`, and strips `tests/` plus the tooling configs from the packaged zip.

**Deliberate deviation:** files do **not** declare `strict_types=1`. Option values throughout the plugin flow as loose scalars (`''`, `'0'`, `false`) through untyped helpers, so enabling strict typing wholesale without a WordPress integration test harness would risk silent `TypeError`s at runtime. Prefer adding parameter/return types (as most SEOCore classes now do) over flipping the strict-types switch file by file.

---

## Testing & Verification Checklist

Before submitting changes:

- [ ] `composer test` — smoke script + PHPUnit suite pass
- [ ] `composer analyse` — no new PHPStan errors outside the baseline
- [ ] `node -c crawlwp-metabox.js` — JS syntax check passes
- [ ] `php -l Assets.php` — PHP syntax check passes
- [ ] `php -l metabox-template.php` — PHP syntax check passes
- [ ] `php -l FrontendHead.php` — PHP syntax check passes (if modified)
- [ ] `php -l MetaFields.php` — PHP syntax check passes (if modified)
- [ ] `php -l MetaBox.php` — PHP syntax check passes (if modified)
- [ ] Test in Classic Editor — no console errors
- [ ] Test in Gutenberg Block Editor — no console errors
- [ ] Title/slug sync works bidirectionally in both editors
- [ ] Analysis and Links tabs update on content changes
- [ ] Score ring updates dynamically
