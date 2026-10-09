# Holvek — Production Backlog
> **Last updated:** September 2026 | Ordered by **impact on site growth**, descending.
> Linked spec: [specification.md](file:///Applications/XAMPP/xamppfiles/htdocs/holvek/specification.md) · [tasklist.md](file:///Applications/XAMPP/xamppfiles/htdocs/holvek/tasklist.md)

---

## How items are ranked

Growth impact is scored across four signals:

| Signal | Weight |
|---|---|
| Directly affects Google crawl / indexation | High |
| Affects keyword rankings or content discoverability | High |
| Drives plugin retention / activation-to-value speed | Medium |
| Enables monetisation or upsell | Medium |

---

## 🔴 Tier 1 — Critical Growth Blockers
*Missing these actively harms SEO or makes the plugin unusable for real sites.*

---

### BL-001 · One-Click Redirect from 404 Monitor
**Spec:** `TT-002` | **Phase:** 2 | **Status:** Partial (data ready, UX missing)

The 404 monitor captures broken URLs with referrer data but provides no way to fix them from within the same screen. Users must leave the 404 table, go to the Redirect Manager, and manually retype the URL — a workflow that causes abandonment.

**Growth impact:** Every uncorrected 404 is a crawl budget leak and a lost backlink. Fixing this closes the loop on the most common technical SEO task.

**Acceptance criteria:**
- [ ] Each row in the 404 log table has a **"Create Redirect →"** action button
- [ ] Clicking it pre-fills the Redirect Manager form with `source = logged URI`
- [ ] On save, the 404 log entry is marked as resolved and visually distinguished
- [ ] Bulk action: select multiple 404s and redirect all to a single target URL

---

### BL-002 · Orphaned Content Finder
**Spec:** `TT-004` | **Phase:** 2 | **Status:** Pending

Pages with zero inbound internal links are invisible to crawlers that follow the link graph. WordPress sites routinely accumulate dozens of these after content reorganisation.

**Growth impact:** Orphaned pages do not pass PageRank and are often deindexed. Surfacing and fixing them directly increases crawled-page count and internal link equity distribution.

**Acceptance criteria:**
- [ ] Background query identifies all published posts/pages with no `<a href>` pointing to them from other published content
- [ ] Results paginated in admin table: post title, type, date, inbound link count
- [ ] Quick-action per row: add to an existing post's content, assign to a category, or create a redirect
- [ ] Runs as a low-priority cron job (not on page load)
- [ ] Results cached and refreshed weekly or on-demand

---

### BL-003 · ImageObject Schema Injection
**Spec:** *(gap — not in spec)* | **Phase:** 1 | **Status:** Missing

Every image served on a post should have `ImageObject` structured data so Google Image Search can surface it with rich metadata (creator, license, caption). Currently no schema is emitted for images.

**Growth impact:** Image Search is a distinct traffic channel. Sites with `ImageObject` schema are eligible for image licensing badges and AI-powered visual carousels in SERPs.

**Acceptance criteria:**
- [ ] On singular posts, inject `ImageObject` JSON-LD for every image in post content and the featured image
- [ ] Fields: `@type`, `url`, `contentUrl`, `width`, `height`, `name` (from alt text), `caption`, `author` (site name), `license` (optional setting)
- [ ] Deduplicates images that appear more than once
- [ ] Setting to enable/disable per post type
- [ ] Validates against Google's Rich Results Test schema

---

### BL-004 · SEO-Friendly Image Filename Enforcement
**Spec:** *(gap — not in spec)* | **Phase:** 1 | **Status:** Missing

WordPress accepts uploads with any filename (e.g. `IMG_9823.jpg`, `screenshot 2024.png`). Crawlers use filenames as a relevance signal for image search ranking.

**Growth impact:** Descriptive, hyphenated filenames are a confirmed Google Image Search ranking factor. This is a free, permanent SEO improvement applied at upload time.

**Acceptance criteria:**
- [ ] On `wp_handle_upload`, sanitise filename: lowercase, spaces/underscores → hyphens, remove special characters, strip generic patterns (`img-`, `dsc-`, `screenshot-`)
- [ ] If focus keyword is set on the parent post at upload time, prepend it to the filename
- [ ] Rename the physical file and update `wp_posts.guid` and all attachment metadata
- [ ] Admin setting: enable/disable auto-rename; option to set a global prefix (e.g. brand name)
- [ ] Retroactive bulk-rename tool for existing library images

---

### BL-005 · Bulk CSV Redirect Import / Export
**Spec:** `TT-001` | **Phase:** 2 | **Status:** Pending

Sites migrating from another platform or domain need to import hundreds of redirects at once. Current REST API only supports single-rule creation.

**Growth impact:** This is the #1 blocker for agencies migrating client sites to Holvek. Without it, they keep using dedicated redirect plugins alongside Holvek, reducing stickiness.

**Acceptance criteria:**
- [ ] CSV import endpoint: `source, target, code, is_regex` columns; validates each row before insert
- [ ] Import preview step showing parse results and validation errors before committing
- [ ] Duplicate detection per row; option to skip or overwrite existing rules
- [ ] CSV export of all current redirect rules
- [ ] Bulk delete by selection in the redirect table

---

### BL-006 · Multi-Point Technical SEO Audit
**Spec:** `AR-003` | **Phase:** 3 | **Status:** Pending

An automated site-wide audit is the single feature users associate with professional SEO tools (Ahrefs, Semrush, Screaming Frog). Its absence is the biggest perceived gap versus competitors.

**Growth impact:** Audit results are the primary driver for users discovering and acting on Holvek recommendations. Also the core upsell hook for the Pro tier.

**Acceptance criteria:**
- [ ] Audit engine checks 40+ factors across: meta tags, canonical, robots, schema, images, page speed signals, Core Web Vitals (via PageSpeed API), internal linking, sitemap coverage
- [ ] Results grouped by severity: Critical / Warning / Passed
- [ ] Per-URL drill-down with fix instructions linked to relevant Holvek settings
- [ ] Scan runs via chunked background queue (not blocking HTTP)
- [ ] Exportable PDF/CSV report
- [ ] Re-run on schedule (weekly) with email diff of new issues

---

### BL-007 · ALT Text Keyword Awareness
**Spec:** *(gap — not in spec)* | **Phase:** 1 | **Status:** Missing

The current `autoSetAltText()` derives alt text from the filename (e.g. "My Image 2024"). It does not incorporate the post's focus keyword, which is the primary SEO signal Google uses when ranking images.

**Growth impact:** Keyword-bearing alt text is a direct on-page SEO signal. Images are an untapped source of keyword density on content-heavy sites.

**Acceptance criteria:**
- [ ] When auto-generating alt text on upload, check if the parent post has a `_holvek_focus_keyword` set; if so, prepend or incorporate it naturally: `"{keyword} – {cleaned filename}"`
- [ ] In the alt audit table, flag images whose alt text does not contain any of the parent post's focus keywords
- [ ] Bulk-fix: auto-update flagged alt texts to include the focus keyword
- [ ] Never overwrite manually set alt text

---

### BL-008 · CCPA Privacy Tools
**Spec:** `COMP-001` | **Phase:** 1 | **Status:** Pending

Required for any US-facing site. Without it, Holvek is non-compliant with the legal obligations stated in the specification itself.

**Growth impact:** Compliance is a prerequisite for enterprise/agency adoption. Also gates the Pro tier launch in the US market.

**Acceptance criteria:**
- [ ] Data inventory: log what user data Holvek stores (search tracking metrics, 404 logs, API keys)
- [ ] Data export endpoint: download all stored data for a given user/email as JSON
- [ ] Data deletion workflow: one-click purge of user-linked data with confirmation
- [ ] "Do Not Sell" signal: honour GPC (Global Privacy Control) header and suppress AI features accordingly
- [ ] Retention policy: configurable auto-purge of 404 logs and usage metrics after N days

---

## 🟠 Tier 2 — High Growth Leverage
*Measurable improvements to rankings, retention, or conversion.*

---

### BL-009 · Contextual Internal Linking Suggestions
**Spec:** `ACO-004` | **Phase:** 2 | **Status:** Pending

Internal links are one of the strongest free ranking signals. Most users add them manually, rarely, and inconsistently.

**Growth impact:** Studies consistently show internal link improvements lift rankings within weeks. This is a high-frequency in-editor interaction that increases DAU.

**Acceptance criteria:**
- [ ] As the editor loads, query existing published content for posts with overlapping focus keywords
- [ ] Display up to 5 contextual link suggestions in the sidebar with anchor text, target URL, and one-click insert
- [ ] Exclude posts already linked in the current content
- [ ] Debounce: suggestions update only when the user pauses typing for 2s
- [ ] Flag orphaned posts (from BL-002) as high-priority link targets

---

### BL-010 · Editable AI Bot List
**Spec:** `CF-008` | **Phase:** 1 | **Status:** Partial (hardcoded list)

The AI crawler list in `RobotsTxtEditor.php` is a PHP constant. New bots (Bytespider, Meta-ExternalAgent, Diffbot, etc.) require a code deployment to block.

**Acceptance criteria:**
- [ ] Admin UI: manage a list of custom bot user-agent strings to block
- [ ] Merge with the hardcoded default list at runtime
- [ ] REST endpoint: GET/POST for the custom bot list
- [ ] "Check for new known AI bots" — periodic fetch from a community-maintained list (admin-configurable URL)

---

### BL-011 · Usage Metering & Token Limits
**Spec:** `AI-INF-005`, `BILL-004` | **Phase:** 1 | **Status:** Pending

Without token tracking, users can unknowingly exhaust their API keys, resulting in broken AI features and support requests.

**Acceptance criteria:**
- [ ] Track token usage per provider, per feature, per WordPress user
- [ ] Admin dashboard widget: tokens used this month vs. configured limit
- [ ] Soft cap: warn at 80% of limit; hard cap: disable AI features at 100%
- [ ] Configurable limits per tier in the feature flag system
- [ ] Weekly usage email digest (opt-in)

---

### BL-012 · Subscription Upgrade Prompts
**Spec:** `BILL-003` | **Phase:** 1 | **Status:** Pending

The free→paid conversion path has no in-product touchpoints. Users who would pay are not being prompted.

**Acceptance criteria:**
- [ ] Contextual upgrade modals triggered when a free-tier user attempts a Pro feature
- [ ] Upgrade banner in dashboard header (dismissible, respects dismissed flag per user)
- [ ] Feature comparison table accessible from the upgrade modal
- [ ] Upgrade links track UTM source for attribution

---

### BL-013 · LLM-Driven Writing Assistant
**Spec:** `ACO-001` | **Phase:** 2 | **Status:** Pending

> ⚠️ **Guardrail:** 3,000ms debounce minimum; "Analyze" button primary interaction mode to prevent API flooding.

**Acceptance criteria:**
- [ ] Real-time keyword density feedback in the Gutenberg sidebar (debounced)
- [ ] On-demand "Improve this paragraph" via LLM with user's API key
- [ ] Meta description and title suggestions generated from post content
- [ ] AI-generated suggestions labelled with FTC-compliant disclosure tag
- [ ] Prompt templates per content type (blog post, product page, landing page)

---

### BL-014 · .htaccess Safe Visual Editor
**Spec:** `TT-005` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Read current `.htaccess` via `file_get_contents` with fallback message if file unreadable
- [ ] Syntax-highlighted textarea (CodeMirror or Monaco)
- [ ] Auto-backup to `wp-content/holvek-backups/htaccess-{timestamp}.txt` before every save
- [ ] Basic syntax validation: unmatched `<IfModule>` blocks, invalid rewrite flags
- [ ] One-click restore from backup list
- [ ] Capability guard: `manage_options` only

---

### BL-015 · Sitemap Priority & ChangeFreq Controls
**Spec:** `SI-005` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Per post-type settings: default `priority` (0.0–1.0) and `changefreq`
- [ ] Per-URL override via post meta
- [ ] Exclude specific URLs or patterns from sitemap without adding `noindex`

---

### BL-016 · FTC AI Disclosure Labels (Complete)
**Spec:** `COMP-002` | **Phase:** 1 | **Status:** Partial

Editor controls exist; front-end label output is missing.

**Acceptance criteria:**
- [ ] When `_holvek_ai_generated` meta is set, inject a visible `<aside>` disclosure in rendered output (filterable position)
- [ ] Notice text configurable in settings; defaults to FTC-compliant language
- [ ] Schema flag: add `additionalProperty` to Article schema indicating AI assistance
- [ ] Visible indicator in post list column

---

### BL-017 · Post Index Status Checker
**Spec:** `SI-004` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Per-URL "Check Index Status" in post list → queries Google URL Inspection API
- [ ] Status badge: Indexed / Not Indexed / Crawled but not indexed / Discovered
- [ ] Bulk check for selected posts
- [ ] Troubleshooting tips per non-indexed status

---

### BL-018 · Content Gap Analysis
**Spec:** `ACO-002` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Input: target keyword or competitor URL
- [ ] Output: sub-topics that top-ranking pages cover but this site's content does not
- [ ] Pre-populate gap topics as draft post suggestions
- [ ] Powered by user's API key

---

## 🟡 Tier 3 — Competitive Differentiation
*Features that deepen engagement and enable premium positioning.*

---

### BL-019 · Front-End SEO Inspector Overlay
**Spec:** `TT-008` | **Phase:** 2 | **Status:** Pending

> ⚠️ **Guardrail:** Shadow DOM isolation; `current_user_can('edit_posts')` gate only.

**Acceptance criteria:**
- [ ] Floating toggle for logged-in editors showing live `<head>` meta audit
- [ ] Highlight missing fields in red; present fields in green
- [ ] Does not interfere with page cache or CDN

---

### BL-020 · Live Schema Output Validator
**Spec:** `TT-009` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] "Validate Schema" button per post → Google Rich Results Test API
- [ ] Results inline: eligible rich result types, errors, warnings
- [ ] Highlight the specific JSON-LD block causing each error
- [ ] 1h result cache

---

### BL-021 · IndexNow Integration
**Spec:** `SI-003` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Auto-submit URL to IndexNow on publish/update (Bing, Yandex)
- [ ] Key auto-generated; `/{key}.txt` virtual file served
- [ ] Submission log with status codes per URL

---

### BL-022 · Stale Cornerstone Content Detector
**Spec:** `CS-001` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Configurable staleness threshold (default: 6 months)
- [ ] Dashboard widget listing stale cornerstone posts
- [ ] Email alert when a cornerstone post crosses the threshold
- [ ] One-click "Mark as refreshed"

---

### BL-023 · Recurring SEO Audit Scheduler
**Spec:** `CS-003` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Schedule weekly/monthly audits via WP-Cron
- [ ] Email report: new issues, resolved issues, health score trend
- [ ] Audit history (last 12 runs) viewable in dashboard

---

### BL-024 · Search Intent Classifier
**Spec:** `ACO-003` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Classify intent: informational / commercial / navigational / transactional
- [ ] Intent badge in post list and editor sidebar
- [ ] Optimisation suggestions per intent type

---

### BL-025 · Morphological Keyword Matching
**Spec:** `ACO-006` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Keyword analysis recognises inflected forms ("run" → "running", "runs")
- [ ] Stem/lemma library; English first
- [ ] Morphological matches shown separately in keyword density score

---

### BL-026 · WooCommerce Product Schema Mapper
**Spec:** `EC-001` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Auto-generate `Product` schema: name, sku, price, availability, image, brand, aggregateRating
- [ ] Variable product support
- [ ] `AggregateRating` from WooCommerce reviews

---

### BL-027 · Headless WordPress REST API SEO Endpoints
**Spec:** `HEAD-001` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] `GET /holvek/v1/seo/{post_id}` — all SEO meta for a post
- [ ] `PUT /holvek/v1/seo/{post_id}` — update meta title, description, robots, canonical
- [ ] `GET /holvek/v1/seo/global` — site-wide defaults

---

### BL-028 · Inclusive Language Checker
**Spec:** `ACO-007` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Flags non-inclusive phrases with suggested alternatives
- [ ] User-configurable exceptions list
- [ ] Warnings in content score sidebar (non-blocking)

---

### BL-029 · Word Complexity Scoring
**Spec:** `ACO-008` | **Phase:** 2 | **Status:** Pending

**Acceptance criteria:**
- [ ] Highlight polysyllabic words (3+ syllables) not in a common-word whitelist
- [ ] "X complex words found" metric with simpler alternatives
- [ ] Integrates with Flesch-Kincaid readability score

---

### BL-030 · Google Search Console Integration
**Spec:** `AR-001` | **Phase:** 3 | **Status:** Pending

**Acceptance criteria:**
- [ ] OAuth2 GSC connection
- [ ] Impressions, clicks, CTR, position per URL
- [ ] "Top Declining Keywords" widget

---

### BL-031 · Keyword Rank Tracker
**Spec:** `AR-002` | **Phase:** 3 | **Status:** Pending

**Acceptance criteria:**
- [ ] Track N keywords across Google, Bing (N = tier-gated)
- [ ] Historical position chart (30/90/180 day)
- [ ] Rank drop alert notifications

---

### BL-032 · LLM Training Data Opt-Out Directives
**Spec:** `AI-008` | **Phase:** 3 | **Status:** Pending

**Acceptance criteria:**
- [ ] One-click adds `noai`/`noimageai` to `robots.txt` and meta tags
- [ ] Serve `tdm-reservation: 1` HTTP header
- [ ] Per-post override: opt individual posts back in

---

### BL-033 · `llms.txt` Generator Enhancement
**Spec:** `AI-001` | **Phase:** 3 | **Status:** Partial (basic version live)

**Acceptance criteria:**
- [ ] Auto-populate from site description and cornerstone content URLs
- [ ] `# Disallow` / `# Allow` sections for AI training control
- [ ] In-dashboard preview and manual edit
- [ ] Invalidate on content structure change

---

## 🔵 Tier 4 — Enterprise & Platform Expansion

*Phase 3 features gating the enterprise tier and platform breadth.*

| ID | Feature | Spec ID | Notes |
|---|---|---|---|
| BL-034 | Granular Custom SEO User Roles | `UM-001` | `manage_seo`, `view_reports`, `manage_redirects` capabilities |
| BL-035 | WordPress Multisite Support | `IN-003` | Network-level settings + per-site overrides |
| BL-036 | Page Builder SEO Wrappers | `IN-001` | Elementor, Divi, WPBakery native integration |
| BL-037 | PageSpeed / Core Web Vitals Dashboard | `AR-007` | Per-URL CWV data via PageSpeed Insights API |
| BL-038 | Keyword Cannibalization Detector | `AR-005` | Flag posts competing for the same keyword |
| BL-039 | Schema Import from Competitor URL | `ASD-003` | Scrape + adapt competitor structured data |
| BL-040 | AI Visibility Tracker | `AI-002` | Monitor citations across ChatGPT, Perplexity, Gemini |
| BL-041 | Multi-Brand Agency Workspaces | `UM-002` | Isolated SEO environments per client brand |
| BL-042 | System-Wide Audit History Log | `UM-004` | Audit trail of all SEO setting changes with rollback |
| BL-043 | Google Analytics 4 Integration | `IN-006` | GA4 traffic + behaviour alongside SEO metrics |
| BL-044 | Yoast / AIOSEO Importer Completion | `IN-004` | Full settings parity import from competitors |
| BL-045 | Laravel Package Foundation | `IN-008` | Core SEO logic as installable Laravel service provider |
| BL-046 | BYO Enterprise LLM Gateway | `AI-010` | Private model endpoint for enterprise tier |
| BL-047 | Team SEO Comments & Approval Workflows | `UM-003` | Task assignments, SEO gatekeeping on publish |
| BL-048 | Client Reporting Portal | `UM-005` | White-label dashboard for agency clients |
| BL-049 | Broken Link Scanner (SaaS-deferred) | `TT-003` | Via webhook to external microservice; not native crawl |
| BL-050 | Autonomous Internal Linking Agent | `AUTO-001` | Decoupled cloud worker; plugin publishes endpoints only |
