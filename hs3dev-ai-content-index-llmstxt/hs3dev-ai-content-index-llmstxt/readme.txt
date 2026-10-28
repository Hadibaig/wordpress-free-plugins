=== HS3Dev AI Content Index for llms.txt ===
Contributors: mirzahadi
Tags: llms, ai, seo, content generation, crawler
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate llms.txt and llms-full.txt with full control over content, SEO integration, multilingual support, and AI crawler analytics.

== Description ==

HS3Dev AI Content Index for llms.txt allows WordPress site owners to automatically generate structured llms.txt and llms-full.txt files for AI systems and large language model crawlers.

It provides full control over included content, supports SEO meta integration, multilingual setups, and ensures safe performance even on large websites through batch processing.

Developed by Mirza Hadi (HS3Dev).

== Features ==

- Generate llms.txt and llms-full.txt files dynamically (no physical file required)
- Select content by post type or individual posts/pages/products
- Include/exclude specific URLs manually
- SEO plugin integration (Yoast, RankMath, AIOSEO, SEOPress)
- Page-builder safe content extraction
- Compatibility mode for live URL content fetching
- Batched generation for large websites
- Auto-regeneration via WP-Cron (on save, daily, weekly)
- AI crawler analytics (GPTBot, ClaudeBot, PerplexityBot, etc.)
- Multilingual support (WPML / Polylang)
- Multisite compatible
- Conflict detection and diagnostics tools

== Installation ==

1. Upload plugin to /wp-content/plugins/
2. Activate the plugin
3. Go to HS3Dev AI Content Index settings
4. Configure post types and options
5. Save Permalinks once after activation

== Frequently Asked Questions ==

= Does it create physical files? =
No, it serves virtual llms.txt files dynamically through WordPress.

= Does it work with Elementor or page builders? =
Yes, it supports both filtered content and live-fetch compatibility mode.

== Changelog ==

= 0.6.9 =
* Fixed: Plugin Check findings - input sanitization on URL list fields, documented exceptions for third-party/core hook names and the plugin's own log table insert.
* Updated: Tested up to WordPress 7.1.

= 0.6.8 =
* Fixed: on network activation, the /llms.txt and /llms-full.txt rewrite rules are now registered and flushed for every site in the network, not only the current one.
* Improved: generation no longer re-reads and re-writes the full accumulated output on every batch. Each batch now stores only its own output, so database work scales linearly on large sites.
* Fixed: taxonomy terms selected in settings are now included in llms.txt.
* Fixed: temporary generation data is now fully removed when a run finishes or is cancelled.

= 0.6.7 =
* Optimised transient storage: generation progress queue now uses two flat integer arrays (post_queue, term_queue) instead of an array of tagged {type,id} objects, reducing serialised transient size by ~75% on large sites (e.g. ~50KB instead of ~200KB for a 4,000-item site).
* Fixed: the "done" check now correctly detects when both post_queue and term_queue are empty.

= 0.6.0 =
* Renamed plugin (display name, slug, text domain, and full internal code prefix) to "HS3Dev AI Content Index for llms.txt" per WordPress.org Plugin Review Team naming guidance, to place the "llms" term correctly at the end of the name and avoid trademark/category confusion.
* Renamed internal code prefix from llmsg_/LLMS_Generator_ to hs3daci_/HS3DACI_, since "llms" was flagged as too common a word to safely function as a collision-safe prefix.
* Fixed Tested up to value (was referencing a WordPress version that does not exist).
* Added plugin owner to Contributors list.

= 0.5.1 =
* Renamed slug/text-domain to hs3dev-llms-generator
* Fixed admin asset loading after rename
* Hardened analytics database queries (prepared statements with %i identifier placeholder)
* Fixed invalid "Requires at least: 7.0" value and other readme metadata issues
* General WordPress coding standards cleanup

= 0.5.0 =
* Added description toggle for llms.txt output (full vs minimal mode)
* Improved flexibility for SEO + AI crawler output control

= 0.4.0 =
* Added multilingual support (WPML/Polylang)
* Added compatibility mode for page builders
* Added multisite support and role control

= 0.3.0 =
* Added batch processing system for large sites
* Added conflict detection for physical llms.txt files
* Improved performance and memory handling

= 0.2.0 =
* Added SEO meta integration
* Added AI crawler analytics
* Added WP-Cron auto regeneration

= 0.1.0 =
* Initial release