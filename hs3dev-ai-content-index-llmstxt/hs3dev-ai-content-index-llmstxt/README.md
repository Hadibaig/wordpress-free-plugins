# HS3Dev LLMS Generator

Generate llms.txt and llms-full.txt for your WordPress site, with full control over what's included — no physical files written, no theme/page-builder conflicts.

Developed by Mirza Hadi (HS3Dev) — hadi-mirza.com

## Features

- Three-tab admin UI: Generate, Analytics, Settings
- Per-post-type inclusion, plus individual post/page/product include/exclude checkboxes
- Manual include/exclude URL lists for content outside the standard post types
- Page-builder-safe content extraction (Elementor, WPBakery, UX Builder, etc.) via `the_content` filtering, with an optional "compatibility mode" that fetches the live published URL instead, for builders that don't render cleanly in-process
- SEO meta description integration: pulls summaries from Yoast SEO, RankMath, All in One SEO, or SEOPress when available, falling back to the post excerpt, then an auto-trimmed snippet
- Batched, resumable generation with a progress bar — safe for sites with thousands of posts/products, no PHP timeout risk
- Auto-regeneration on a schedule: manual, on save (debounced), daily, or weekly via WP-Cron
- Analytics dashboard: total requests, daily trend chart, breakdown by detected AI crawler (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot, Bytespider, and more), and a recent-requests log
- Diagnostics: detects physical llms.txt/llms-full.txt files that would silently override this plugin's output, and a "Verify Live File" check that fetches the public URL (cache-busted) and compares it against what's stored, to distinguish caching issues from real conflicts
- Multilingual support: detects WPML or Polylang, generates a separate file per language, and serves the correct one based on subdirectory URL, `?lang=` query string, or the active multilingual plugin's current language
- Configurable role capability (Administrator / Editor / Author) for who can manage settings and trigger generation
- Multisite-safe: all settings, generated content, and analytics are scoped per site; network activation/deactivation loops correctly across all sites, and newly created sites on a network-activated install are set up automatically

## Setup notes

- After activating (or updating files that changed rewrite rules), visit Settings → Permalinks and click Save once to flush rewrite rules, so `/llms.txt` and `/llms-full.txt` resolve correctly.
- If another plugin (Yoast SEO, RankMath, etc.) has its own llms.txt feature enabled, it may compete for the same URL. Disable the other plugin's feature, then use "Verify Live File" on the Generate tab to confirm which version is actually being served — caching can delay the switch.
- A physical `llms.txt` or `llms-full.txt` file in the site's root directory always overrides this plugin's output, since static files are served before WordPress loads. The plugin will warn you if one is detected.

## Known limitations / not yet built

- No WP-CLI command (generation is triggered via the admin UI or cron only)
- No per-language Settings overrides (content selection rules apply the same way across all languages; only the output content itself is language-specific)
- No built-in scheduled report/email of analytics data

## Changelog

### 1.0.0
- Fixed: Multisite network activation now registers and flushes `/llms.txt` and `/llms-full.txt` rewrite rules for every site in the network.
- Improved: Batch generation reduces repeated database reads and writes for accumulated `llms-full.txt` content and taxonomy indexing to improve scalability on large sites.
- Fixed: Taxonomy terms selected in settings are included in `llms.txt`.
- Fixed: Temporary generation data is removed when a generation run finishes or is cancelled.
- Fixed: Addressed Plugin Check findings related to input sanitization and documented exceptions for third-party/core hook names and the plugin's own log table insert.
- Reviewed: Plugin activation, database operations, admin asset loading, compatibility, and external communication behavior in response to WordPress.org Plugin Team feedback.
- Updated: Prepared the first stable release for further review by the WordPress.org Plugin Team.

### 0.6.9
- Fixed: Plugin Check findings - input sanitization on URL list fields, documented exceptions for third-party/core hook names and the plugin's own log table insert.
- Updated: Tested up to WordPress 7.1.
### 0.5.1
- Renamed plugin slug/text-domain from `llms-generator` to `hs3dev-llms-generator` to avoid collision with the existing public "LLMS.txt Generator" plugin, per WordPress.org naming guidance.
- Fixed all text-domain strings across templates and includes to match the new slug (previously still referenced the old `llms-generator` domain, which silently broke translation loading).
- Fixed an admin asset-loading bug introduced by the rename: the admin page hook check still referenced the old menu slug (`toplevel_page_llms-generator` instead of `toplevel_page_hs3dev-llms-generator`), so admin CSS/JS stopped loading after renaming.
- Hardened database queries in the analytics logger to use `$wpdb->prepare()` with the `%i` identifier placeholder for table names, resolving WordPress Plugin Check warnings about unprepared/direct queries.
- Prefixed all template-level variables (`llmsg_` prefix) to satisfy WordPress Plugin Check's global-variable-naming convention.
- Corrected an invalid `Requires at least: 7.0` value (WordPress has no 7.0 release) in both the plugin header and `readme.txt`, now consistently `6.8`.
- Fixed `readme.txt`: trimmed tags to the 5-tag limit, trimmed the short description under the 150-character limit, corrected `Tested up to`.
- Fixed malformed `phpcs:ignore` comments for `set_time_limit()`/`ini_set()` that referenced incorrect sniff names and weren't actually suppressing anything.

### 0.5.0
- Added a checkbox (on both the Generate tab and Settings) to control whether llms.txt entries include a description at all. Unchecked shows just the heading and URL per entry; checked adds the description (sourced via the existing SEO meta / excerpt / auto-trim priority chain). Different companies/clients prefer different verbosity, so this is now a one-click toggle rather than always-on.

### 0.4.0
- Added compatibility mode (live URL fetch) as a fallback for page builders that don't render cleanly through `the_content`
- Added WPML/Polylang multilingual support with per-language generation and serving
- Added configurable role capability for delegating plugin access below Administrator
- Added multisite-safe activation/deactivation and automatic setup for new sites on a network
- Updated developer credits

### 0.3.0
- Added "Verify Live File" diagnostic and proactive physical-file-conflict warning
- Added batched, resumable generation with a progress bar
- Raised resource limits defensively for the synchronous (cron) generation path
- Generated content option now saved with autoload disabled

### 0.2.0
- Added SEO meta description integration (Yoast, RankMath, AIOSEO, SEOPress)
- Added WP-Cron auto-regeneration (on save, daily, weekly)
- Added Analytics tab with AI bot detection and request logging

### 0.1.0
- Initial release: Generate/Analytics/Settings tabs, per-post-type and per-item content selection, virtual file serving for /llms.txt and /llms-full.txt
