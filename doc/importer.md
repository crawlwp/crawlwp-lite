# CrawlWP SEO Core — Importer Feature Documentation

## 1. Overview & Objective

The **Importer** feature in CrawlWP (`mihdan-index-now`) enables seamless, zero-downtime migration of SEO metadata, site-wide configurations, and redirect rules from third-party WordPress SEO plugins into CrawlWP.

Switching SEO plugins poses significant risks to search engine visibility, organic traffic, and operational continuity if metadata (titles, descriptions, robots directives, canonical URLs, structured data, redirects) is lost or corrupted. The Importer maximizes migration utility and data integrity by:
1. **Preventing Data Loss & Downtime:** Systematically scanning, converting, and migrating historical SEO configurations.
2. **Preventing Server Overload:** Employing a client-orchestrated, batched execution model that eliminates PHP execution timeouts and memory exhaustion on large sites.
3. **Respecting Existing Content:** Utilizing non-destructive defaults with optional overwrite control.
4. **Preserving Dynamic Placeholders:** Converting plugin-specific macro templates (e.g., Yoast's `%%title%%` or Rank Math's `%title%`) into CrawlWP's template syntax (`{{ post.title }}`).

---

## 2. Supported Source Plugins

The Importer provides dedicated adapters for the six leading WordPress SEO plugins:

| Plugin | Source Class | Identifier | Supported Entities |
| :--- | :--- | :--- | :--- |
| **Yoast SEO** | `Sources\Yoast` | `yoast` | Global settings, Posts, Terms, Authors, Redirects |
| **Rank Math** | `Sources\RankMath` | `rankmath` | Global settings, Posts, Terms, Authors, Redirects |
| **All in One SEO (AIOSEO)** | `Sources\AIOSEO` | `aioseo` | Global settings, Posts (v4+ tables & legacy v3), Terms, Authors, Redirects |
| **SEOPress** | `Sources\SEOPress` | `seopress` | Global settings, Posts, Terms, Authors, Redirects (from `seopress_404`) |
| **The SEO Framework (TSF)** | `Sources\TheSEOFramework`| `tsf` | Global settings, Posts, Terms |
| **Slim SEO** | `Sources\SlimSEO` | `slimseo` | Global settings, Posts, Terms, Authors, Redirects |

---

## 3. System Architecture & Components

The feature is located under `src/SEOCore/Importer/` and is structured into distinct, decoupled components:

```
src/SEOCore/Importer/
├── ImporterSettings.php     # Admin UI, asset management, and AJAX controller endpoints
├── Runner.php               # Batch orchestrator, source registry, and stage transition engine
├── Source.php               # Abstract base adapter defining stage contracts and shared utilities
├── TokenMapper.php          # Pure-PHP macro/token replacement engine
├── Writer.php               # Data persistence layer for meta keys, global options, and media caching
├── Sources/                 # Concrete plugin adapters
│   ├── AIOSEO.php
│   ├── RankMath.php
│   ├── SEOPress.php
│   ├── SlimSEO.php
│   ├── TheSEOFramework.php
│   └── Yoast.php
└── assets/                  # Frontend user interface
    ├── importer.css         # Styling for inventory list, status indicators, and logs
    └── importer.js          # Client-side asynchronous polling and stage state machine
```

### Architectural Responsibilities

```
┌────────────────────────────────────────────────────────┐
│                   Browser Client                       │
│        (importer.js — State Machine & Poller)          │
└──────────────┬──────────────────────────▲──────────────┘
               │ 1. AJAX: step/inventory  │ 4. JSON Progress
               ▼                          │
┌─────────────────────────────────────────┴──────────────┐
│                  ImporterSettings                      │
│        (Security Checks, Nonce, Capability)            │
└──────────────┬─────────────────────────────────────────┘
               │ 2. Delegate step execution
               ▼
┌────────────────────────────────────────────────────────┐
│                       Runner                           │
│   (Stage Order: settings → posts → terms → users ...)  │
└──────────────┬─────────────────────────────────────────┘
               │ 3. Execute batch for active source
               ▼
┌────────────────────────────────────────────────────────┐
│                   Source Adapter                       │
│        (e.g., Yoast, RankMath, AIOSEO)                 │
└──────┬──────────────────────┬───────────────────┬──────┘
       │ Reads raw data       │ Translates tokens │ Writes normalized data
       ▼                      ▼                   ▼
┌──────────────┐      ┌──────────────┐    ┌──────────────┐
│  Third-Party │      │ TokenMapper  │    │    Writer    │
│  Meta/Tables │      │   (Tokens)   │    │  (CrawlWP)   │
└──────────────┘      └──────────────┘    └──────────────┘
```

---

## 4. Execution Flow & Lifecycle

The migration lifecycle follows a deterministic four-phase process:

### Phase 1: Discovery & Inventory Inspection
1. The user navigates to **CrawlWP > Settings > Advanced Settings > Import SEO Data**.
2. `ImporterSettings::render_ui()` renders the base interface (`#cwpImporter`).
3. `importer.js` fires an initial AJAX call to `crawlwp_import_inventory`.
4. `ImporterSettings::ajax_inventory()` invokes `Runner::inventory()`.
5. For each registered adapter, `Source::is_available()` checks if the plugin is active, its options exist, or its specific database tables/meta keys are present (allowing import even from deactivated plugins).
6. If available, `Source::counts()` queries the count of posts, terms, users, and redirects holding data.
7. The UI displays each detected source alongside counts and an **Import** button.

### Phase 2: Client-Orchestrated Pumping (State Machine)
1. The user clicks **Import** and confirms the prompt.
2. The UI enters the `is-running` state and activates the log console (`#cwpImporterLog`).
3. `importer.js` initiates the `pump()` function starting at stage `'settings'` and offset `0`.
4. Each request targets `admin-ajax.php?action=crawlwp_import_step` passing:
   - `source`: e.g., `'yoast'`, `'rankmath'`
   - `stage`: Current stage (`'settings'`, `'posts'`, `'terms'`, `'users'`, or `'redirects'`)
   - `offset`: Current record offset
   - `overwrite`: `1` or `0` depending on the checkbox
   - `nonce`: Cryptographic CSRF token (`crawlwp_importer_nonce`)

### Phase 3: Batched Stage Execution (`Runner::run_step`)
1. Security validation verifies the user has the `manage_options` capability and checks the nonce.
2. `Runner::run_step()` directs the request to the corresponding stage method on the adapter:
   - **`settings`**: Single batch. Reads source settings, maps to CrawlWP structure, writes to options via `Writer::write_settings()`.
   - **`posts`**: Batches of 50. Uses `Source::post_ids()` and pre-warms postmeta cache via `update_meta_cache()`.
   - **`terms`**: Batches of 50. Queries public taxonomies and pre-warms termmeta cache.
   - **`users`**: Batches of 50. Queries authors holding SEO meta and pre-warms usermeta cache.
   - **`redirects`**: Batches of 50. Reads source redirect tables or options, writing to CrawlWP's redirect storage.
3. The adapter returns:
   - `imported`: Count of newly written items.
   - `skipped`: Count of skipped items (e.g., already set when overwrite is false, or invalid).
   - `done`: Boolean indicating if the current stage has finished all records.
   - `next_offset`: Next offset index for pagination.
4. `Runner` computes `next_stage`:
   - If `done` is `false`, `next_stage` stays on the current stage with updated `next_offset`.
   - If `done` is `true`, `next_stage` advances to the next stage in `['settings', 'posts', 'terms', 'users', 'redirects']` and resets `next_offset` to `0`.
   - When the final stage completes, `next_stage` becomes `'done'`.

### Phase 4: Completion & Cache Busting
1. The client logs progress for each batch.
2. When `next_stage === 'done'`, `importer.js` logs completion with overall totals.
3. The UI clears the loading state and updates user feedback.
4. On the backend, `Writer::write_settings()` ensures internal option caches are cleared via `Options::flush_cache()`.

---

## 5. Detailed Component Breakdown

### 5.1. TokenMapper (`TokenMapper.php`)

Other plugins use proprietary template variable syntax in titles and descriptions. `TokenMapper` translates these into CrawlWP's native `{{ namespaced.token }}` format using direct string translation (`strtr()`).

#### Mapping Examples

| Source Plugin | Foreign Token | CrawlWP Equivalent | Description |
| :--- | :--- | :--- | :--- |
| **Yoast** | `%%title%%` | `{{ post.title }}` | Post/Page title |
| **Yoast** | `%%sep%%` | `{{ sep }}` | Title separator |
| **Yoast** | `%%sitename%%` | `{{ site.title }}` | Site title |
| **Yoast** | `%%excerpt%%` | `{{ post.auto_description }}` | Generated excerpt |
| **Rank Math** | `%title%` | `{{ post.title }}` | Post/Page title |
| **Rank Math** | `%sep%` | `{{ sep }}` | Title separator |
| **Rank Math** | `%post_thumbnail%` | `{{ post.thumbnail }}` | Featured image URL |
| **AIOSEO** | `#post_title` | `{{ post.title }}` | Post/Page title |
| **AIOSEO** | `#separator_sa` | `{{ sep }}` | Separator character |
| **SEOPress** | `%%post_title%%` | `{{ post.title }}` | Post/Page title |
| **Slim SEO** | `{{ post.title }}` | `{{ post.title }}` | Native match |
| **Slim SEO** | `{{ post.categories }}` | `{{ post.category }}` | Singularized category |

*Note: Unrecognized tokens are retained unmodified to allow manual inspection rather than silently destroying user templates.*

### 5.2. Writer (`Writer.php`)

`Writer` acts as the single source of truth for persisting imported data into WordPress storage.

#### Key Features:
1. **Granular Overwrite Evaluation (`may_write`):**
   - When the **Overwrite** option is disabled, `Writer` checks whether the target field already has a non-empty value.
   - Evaluation happens **per meta field**, not per post/term. If a post already has an SEO Title in CrawlWP but lacks a Meta Description or Canonical URL, the importer preserves the title and safely imports the missing description and canonical URL.
2. **Metadata Canonicalization & Sanitization:**
   - Normalizes robots values: `robots_index` becomes `'index'` or `'noindex'`; `robots_follow` becomes `'follow'` or `'nofollow'`.
   - Validates URLs using `MetaFields::sanitize_url()`.
   - Restricts redirect status codes to supported standards: `'301'`, `'302'`, `'307'`, `'410'`, `'451'`.
   - Sanitizes text and textarea fields (`sanitize_text_field`, `sanitize_textarea_field`).
3. **Social Sync Guarding:**
   - When importing OpenGraph or Twitter-specific titles, descriptions, or images, `Writer` automatically sets:
     - `_crawlwp_og_sync = '0'`
     - `_crawlwp_x_sync = '0'`
   - This prevents CrawlWP's metabox from automatically falling back or overwriting imported social overrides.
4. **Media Resolution & Caching (`to_attachment_id`):**
   - Plugins often store social images as raw URLs rather than WordPress media attachment IDs.
   - `Writer::to_attachment_id()` resolves URLs to media IDs via `attachment_url_to_postid()`.
   - It maintains an in-memory runtime cache (`self::$attachment_ids`) to ensure identical image URLs are resolved only once per request.
5. **Global Settings Mapping (`write_settings`):**
   - Merges source configuration dictionaries into CrawlWP WPOSA option rows (`_crawlwp_entity_*`, `_crawlwp_site_info`, `_crawlwp_social`, `_crawlwp_sitemap_settings`).
   - Flushes option runtime caches via `Options::flush_cache()`.

### 5.3. Source Adapters (`Sources/*`)

Each source adapter implements the `Source` interface and encapsulates vendor-specific data structures:

- **Yoast (`Yoast.php`):**
  - Post meta: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_canonical`, `_yoast_wpseo_opengraph-image`, `_yoast_wpseo_meta-robots-noindex`.
  - Term meta: Stored in the monolithic option `wpseo_taxonomy_meta`. The adapter parses and caches this structure once per request (`$terms_cache`).
  - Redirects: Reads from `wpseo-premium-redirects-base` option.
  - Settings: Unpacks `wpseo_titles`, `wpseo_social`, `wpseo`.
- **Rank Math (`RankMath.php`):**
  - Post/Term/User meta: Prefixed with `rank_math_*` (e.g., `rank_math_title`, `rank_math_description`, `rank_math_robots`, `rank_math_canonical_url`).
  - Redirects: Extracted from custom table `{$wpdb->prefix}rank_math_redirections`.
  - Settings: Unpacks `rank-math-options-general`, `rank-math-options-titles`, `rank-math-options-sitemap`.
- **All in One SEO (`AIOSEO.php`):**
  - Dual Architecture Support: Supports modern AIOSEO v4+ custom database tables (`aioseo_posts`, `aioseo_terms`, `aioseo_redirects`) as well as legacy v3 postmeta (`_aioseop_title`, `_aioseop_description`).
  - Settings: Unpacks JSON and serialized blobs from `aioseo_options`.
- **SEOPress (`SEOPress.php`):**
  - Post/Term/User meta: Prefixed with `_seopress_*`.
  - Redirects: Stored as custom post types (`seopress_404`), extracted and mapped to CrawlWP redirects table.
  - Settings: Unpacks `seopress_titles_option_name`, `seopress_social_option_name`, `seopress_advanced_option_name`.
- **The SEO Framework (`TheSEOFramework.php`):**
  - Post meta: Reads Genesis-compatible keys `_genesis_title`, `_genesis_description`, `_genesis_canonical_uri`, `_genesis_noindex`, `_genesis_nofollow`.
  - Term meta: Reads `autodescription-term-settings`.
  - Settings: Unpacks `autodescription-site-settings`.
- **Slim SEO (`SlimSEO.php`):**
  - Storage: Stores all SEO metadata as a single serialized associative array under the key `slim_seo` on posts, terms, and users.
  - Redirects: Extracted from `{$wpdb->prefix}slim_seo_redirects`.
  - Settings: Unpacks `slim_seo` option.

---

## 6. Performance, Safety & Reliability Considerations

1. **Elimination of N+1 Queries (Meta Cache Pre-Warming):**
   - In `Source::post_ids()`, `Source::terms()`, and `Source::user_ids()`, the adapter calls `prime_meta($object_type, $ids)`.
   - This invokes WordPress core's `update_meta_cache()`, loading all meta rows for all 50 objects in a single SQL query (`SELECT ... WHERE post_id IN (...)`). Subsequent calls to `get_post_meta()` in the loop operate entirely in memory.
2. **Fixed Batch Size (Chunking):**
   - By locking the batch limit to 50 (`Runner::BATCH_SIZE = 50`), memory usage stays bounded below standard PHP script limits regardless of catalog size (100 posts or 500,000 posts).
3. **Database Version Compatibility:**
   - For database table queries across different WordPress versions, `Source::table_query()` checks for WP 6.2's `%i` identifier placeholder support. On older environments, it falls back to strict regex identifier sanitization and backtick escaping (`[^A-Za-z0-9_$]`).
4. **Resumability & Idempotence:**
   - If a network interruption occurs, the import can be restarted safely.
   - When run with default settings (Overwrite disabled), re-running an import will skip already imported fields without duplicating or overwriting data.
5. **Security Enforcements:**
   - User must have `manage_options` capability.
   - Nonce validation (`check_ajax_referer`) protects against CSRF on all actions.
   - All inputs are strictly unslashed, typed, and sanitized.

---

## 7. Summary for Developers & Maintainers

To add support for a new SEO plugin:
1. Create a new class extending `Mihdan\IndexNow\SEOCore\Importer\Source` in `src/SEOCore/Importer/Sources/`.
2. Implement required abstract methods: `id()`, `label()`, `is_available()`, `counts()`, `import_posts()`, `import_terms()`.
3. If the plugin provides template tokens, add mapping entries in `TokenMapper::map_for()`.
4. Register the new source instance in `Runner::sources()`.
5. Add unit tests for token mapping in `tests/unit/TokenMapperTest.php`.
