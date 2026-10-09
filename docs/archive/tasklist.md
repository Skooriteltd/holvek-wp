# Holvek Master Tasklist & Progress Tracker

> **Document Version:** 1.3
> **Target Platform:** Holvek Full Suite (WordPress, Headless API, Laravel Package)  
> **Linked specification:** `specification.md`
> **Last Updated:** 19 September 2026

---

## 📊 High-Level Development Metrics
- **Total Roadmap Requirements:** 69
- **Fully Executed in WordPress Plugin:** 58
- **Partially Executed / Cloud Worker Scaffolding:** 6
- **Deferred / External SaaS Roadmap:** 5

## 🚦 WordPress.org Production-Readiness Release Tracker

> **Authoritative backlog:** `production-readiness-backlog.md`
> **Release status:** **Not ready for WordPress.org submission.** “Implemented” means source changes exist and local lint/unit/build validation passed; it does **not** mean the acceptance criteria are complete. No item below may be changed to complete until its stated integration, browser, package, and policy evidence is attached.

| ID | Status | Current state / next release gate |
| --- | --- | --- |
| PR-001 | In progress | Activation fatal removed and lifecycle code updated. Verify activation, reactivation, deactivation, uninstall, and upgrades in real single-site and multisite WordPress. |
| PR-002 | In progress | Public SEO endpoint no longer returns the admin email or IndexNow key; finish full REST response/role-boundary inventory. |
| PR-003 | In progress | Clean-tag package script and GitHub Actions workflows added. Run a tagged build and install the resulting ZIP in an empty WordPress instance. |
| PR-004 | In progress | Image compression is opt-in with acknowledgement, backups, and restore. Add real image-engine/EXIF/failure tests and operation confirmation coverage. |
| PR-005 | In progress | Privacy callbacks now use direct data methods and valid 404 retention data. Verify exports, erasure, pagination, and retention on a real database. |
| PR-006 | In progress | Generic Google Indexing API use and metadata lookup removed. Test valid eligible submissions, credentials, quotas, and logging against the provider. |
| PR-007 | In progress | Object authorization was added to the changed high-risk endpoints. Audit all REST routes and add role-boundary tests. |
| PR-008 | In progress | Deactivation cleanup and opt-in multisite-aware uninstall code added. Verify all lifecycle effects in actual multisite. |
| PR-009 | Implemented | Unsupported Elementor, Divi, WPBakery, and other builder wrappers were removed from v1.0 scope. Keep deferred unless an approved integration matrix and test suite exist. |
| PR-010 | In progress | Cookie asset path fixed and the notice no longer claims CMP/legal compliance. Complete browser, accessibility, and legal-copy review. |
| PR-011 | In progress | Deprecated Google sitemap pings removed; plugin sitemap is opt-in and core sitemap coexistence is preserved. Verify with core and major SEO plugins. |
| PR-012 | In progress | Redirect, link-check, schema-validator, and competitor-fetch hardening added. Complete outbound-request inventory and SSRF/redirect tests. |
| PR-013 | In progress | Broken-link processing is bounded and batched; monthly schedule added. Add retry/failure observability and scale tests. |
| PR-014 | Pending | Define editorial intent, accessibility-safe ALT behavior, previews, selection, and undo acceptance criteria. |
| PR-015 | Blocked | Existing WPCS debt needs an approved baseline, Plugin Check, and a zero-new-violations CI rule. |
| PR-016 | In progress | Documentation disclosures were updated and unsupported Trends code removed. Complete service-by-service data-flow and terms review. |
| PR-017 | In progress | Several overclaims were removed. Complete screenshot, readme, and feature-by-feature claim audit. |
| PR-018 | Pending | Complete safe onboarding, conflict detection, and recovery UX. |
| PR-019 | Pending | Validate generated schema/SEO output across supported themes and plugin combinations. |
| PR-020 | Pending | Define versioned migrations, backups, rollback, and import reliability tests. |
| PR-021 | In progress | Lifecycle/rewrite cleanup was added. Verify rewrite rules, cron timing, and upgrade behavior on real WordPress. |
| PR-022–PR-026 | Pending | Performance, observability, capability architecture, accessibility/i18n, and support/compatibility policy remain post-critical hardening work. |

### Release blockers requiring external evidence

- [ ] Real WordPress single-site and multisite lifecycle/privacy integration suite.
- [ ] Tagged ZIP build, archive install smoke test, and browser smoke suite.
- [ ] Complete REST route and outbound-service inventory with authorization/data-flow coverage.
- [ ] WordPress Plugin Check and an approved WPCS baseline with enforcement.
- [ ] Legal/product approval of privacy, consent, external-service, and marketing claims.

---

## 🛠️ Phase 0: Core Architecture & Setup
*Establish clean PSR-compliant OOP boundaries, DI container, event router, and database schema.*
  - Conforms to PSR-12 and WordPress standards.
  - Autoload mapping established for namespace `Holvek\`.
  - Created dynamic event dispatcher managing core Actions and Filters dynamically.
  - Booted React Admin Panel with styled components.
  - Webpack bundles (`editor.js`, `index.js`) compiled safely.
  - Installer creates custom tables for redirects, 404 monitoring logs, and sitemap settings.
  - Background queue runner using `wp_schedule_single_event` to execute jobs asynchronously without blocking page generation.

---

## 🚀 Phase 1: Core Foundation (MVP)
*Essential technical and content SEO automation.*

### Core SEO Foundation

  - Blocks GPTBot, CCBot, Google-Extended, etc. via user-agent header rules and proactive 403 blocks.

### Content & Readability Checklist

  - Scans keyword matches inside Headings, paragraphs, and ALT attributes.
  - Interactive sidebar offering visual warnings for keyword gaps.
  - Weighted algorithmic scores measuring formatting and density.
  - Computes exact Flesch-Kincaid ease scores.
  - Enforces 1,000+ words and strict internal link checks (minimum 3 links) when marked as cornerstone.

### Point-and-Click Schema markup
  - Interactive forms for Article, Product, FAQPage, HowTo, and LocalBusiness schemas.
  - Renders FAQ dropdown blocks, interactive stars ratings, and price lists.
- [x] **Task 1.16: Rich Snippets Schema Validator** `[SD-003]` *(Priority: P1)*
  - *Status: Fully Executed*
  - Connect custom rendered markup to Google Rich Results API validator to display code health.

### Social Media Integrations
 - integrate popular social media platforms and provide the ability to add multiple accounts/profiles per platform
  - Outputs correct og:title, og:description, and Twitter meta.
- [x] **Task 1.18: Direct Social Sharing API & Dashboard triggers** `[SM-004]`
  - Build active visual sharing buttons inside post editor and dashboard list.
  - Integrate API sharing triggers for Facebook, X (Twitter), LinkedIn, and Pinterest using SDK endpoints.
- [x] **Task 1.19: Live Social Sharing Preview Editor** `[SM-002]` *(Priority: P1)*
  - *Status: Executed*
  - Visual cards exist, but need timeline-faithful visual previews mockups in React admin sidebar.
- [x] **Task 1.20: Global Default Fallback Share Image** `[SM-003]` *(Priority: P1)*
  - File-uploader setting to define fallback image for cards when posts lack featured thumbnails.

### AI Foundation & Subscriptions Prep
- [x] **Task 1.21: Secure API Key Vault** `[AI-INF-001]` *(Priority: P0)*
  - *Status: Executed*
  - Key fields exist in admin settings, but secure AES-256 database-level encryption hooks need implementation.
- [x] **Task 1.22: Model Abstraction Gateway** `[AI-INF-002]` *(Priority: P0)*
  - *Status: Executed*
  - Create standardized PHP class to route prompts and fallback elegantly across providers.
- [/] **Task 1.23: Feature Flag Gating System** `[AI-INF-003, BILL-001, BILL-002]` *(Priority: P0)*
  - *Status: Partially Executed*
  - UI options dynamically check capabilities, but need integration with SaaS subscriptions sync.
- [x] **Task 1.24: Local Ollama Endpoint integration** `[AI-INF-004]` *(Priority: P1)*
  - *Status: Executed*
  - Support custom local server URLs with test connection ping test.
- [x] **Task 1.25: Usage Metering and Limits** `[AI-INF-005, BILL-004]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Ai\UsageMeteringManager`)
  - Tracks raw token usage and limits weekly counts based on admin restrictions.
- [x] **Task 1.26: Subscription Upgrade Prompt Flows** `[BILL-003]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Admin\UpgradePromptManager`)
  - In-dashboard alerts and guides linking users to premium upgrades.

### US Compliance Foundation
- [x] **Task 1.27: CCPA Privacy Tools** `[COMP-001]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Compliance\CcpaPrivacyTools`)
  - Automated export/deletion tools for user search tracking metrics and privacy framework exporter/eraser.
- [x] **Task 1.28: FTC AI-Disclosure Labels** `[COMP-002]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Compliance\AiDisclosureManager`)
  - Dynamic AI-generated content disclosures, schema markup integration, and automatic frontend label rendering.

---

## 📈 Phase 2: Growth & Optimization
*Advanced vertical and technical features.*

### Advanced Content Intelligence
- [x] **Task 2.1: LLM-Driven Real-time Writing Assistant** `[ACO-001]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\LlmWritingAssistant`)
  - Gated on-demand click triggers & debounced REST generation to prevent API/thread saturation.
- [x] **Task 2.2: Competitive Content Gap Engine** `[ACO-002]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\ContentGapAnalyser`)
  - Compares content against target topics and identifies coverage gaps with one-click draft creation.
- [x] **Task 2.3: Contextual Internal Link Recommendations** `[ACO-004]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\InternalLinkEngine`)
  - Contextual link suggestions using indexed sentence vectors without heavy full-table scans.
- [x] **Task 2.4: Search Intent Classifier** `[ACO-003]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\SearchIntentClassifier`)
  - Classifies informational, commercial, transactional, and navigational intent.
- [x] **Task 2.5: SEO Metadata Version History Logs** `[ACO-005]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\Admin\SeoAuditHistoryLog`)
  - Tamper-evident change logs tracking user, timestamp, post_id, old/new values with CSV export.
- [x] **Task 2.6: Morphological Focus Keyword Recognition** `[ACO-006]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\MorphologicalKeywordMatcher`)
  - Stemming and pluralization support for keyword density checks.
- [x] **Task 2.7: Inclusive Language Compliance scanner** `[ACO-007]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\InclusiveLanguageScanner`)
  - Scans content for non-inclusive terms and suggests modern, accessible alternatives.
- [x] **Task 2.8: Word Complexity & Simplicity recommendations** `[ACO-008]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\WordComplexityScorer`)
  - Flesch-Kincaid scoring and polysyllabic word simplification engine.

### Verticals: Local, E-Commerce, & Headless APIs
- [x] **Task 2.19: WooCommerce Dynamic Schema mapper** `[EC-001]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Schema\WooCommerceProductSchema`)
  - Dynamic variable product support, Offer schemas, price currency, and AggregateRating.
- [x] **Task 2.20: Product Category & Taxonomies SEO settings** `[EC-002]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Schema\ProductTaxonomySeo`)
  - Per-taxonomy SEO titles, meta descriptions, and schema overrides for WooCommerce categories.
- [x] **Task 2.21: Headless WordPress REST API SEO metadata CRUD** `[HEAD-001]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Api\HeadlessSeoEndpoints`)
  - Full REST API endpoints returning JSON-LD graph, meta tags, and Open Graph objects for Jamstack frontends.
- [x] **Task 2.22: Multi-Location Business Locations schema manager** `[LB-002]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\Schema\MultiLocationSchema`)
  - Support for multi-branch local business profiles with GeoCoordinates and opening hours.
- [x] **Task 2.23: Dynamic Business NAP Shortcode helpers** `[LB-003]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\Schema\NapShortcodes`)
  - Shortcodes `[holvek_nap]` rendering consistent Name, Address, Phone schema markup.
- [x] **Task 2.24: Google Business Profile optimization insights** `[LB-004]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\Schema\GoogleBusinessProfileOptimization`)
  - Profile completeness scoring and audit recommendations.
- [ ] **Task 2.25: Easy Digital Downloads SEO schemas** `[EC-004]` *(Priority: P2)*
  - *Status: Pending* (Enterprise Add-on)
- [ ] **Task 2.26: Headless CMS mutation Webhooks hooks** `[HEAD-002]` *(Priority: P2)*
  - *Status: Pending* (Enterprise Add-on)
- [ ] **Task 2.27: Headless GraphQL Schema fields** `[HEAD-003]` *(Priority: P2)*
  - *Status: Pending* (WPGraphQL Extension)
- [ ] **Task 2.28: Static Site Generator build hooks exporter** `[HEAD-004]` *(Priority: P2)*
  - *Status: Pending* (Jamstack Add-on)

### Hardened Technical SEO Suite
- [x] **Task 2.31: Broken Link & Image Scanner** `[TT-003]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\TechnicalSeo\BrokenLinkChecker`)
  - Safe, rate-limited batch scanning with custom results table and off-peak cron processing.
- [x] **Task 2.32: Inbound Orphaned Page Finder & linking builder** `[TT-004]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\TechnicalSeo\OrphanedPageFinder`)
  - Discovers pages with 0 internal links and provides internal link recommendations.
- [x] **Task 2.33: Stale Cornerstone Content Age Scanner** `[CS-001]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\StaleCornerStoneDetector`)
  - Monitors cornerstone content age with dismissible admin notices and email alerts.
- [x] **Task 2.34: Guided Orphaned Content Remediation Workflow** `[CS-002]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\OrphanContentRemediationWorkflow`)
  - One-click workflow to link or redirect orphaned pages.
- [x] **Task 2.35: Recurring SEO Audits scheduler** `[CS-003]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\ContentAssistant\AuditScheduler`)
  - Weekly automated health scans with issue/warning summaries.
- [x] **Task 2.36: .htaccess Safe visual editor & backups** `[TT-005]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\TechnicalSeo\HtaccessEditor`)
  - Timestamped backup rotations, syntax checks, and blocking of dangerous PHP directives.
- [x] **Task 2.37: Taxonomy base permalinks remover** `[TT-006]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\TechnicalSeo\TaxonomyBaseRemover`)
  - Strips `/category/` from permalinks with automatic 301 redirects.
- [x] **Task 2.38: Parent post media attachment redirect rules** `[TT-007]` *(Priority: P2)*
  - *Status: Fully Executed*
  - Intercepts and redirects thin media attachments cleanly back to their parent pages.
- [x] **Task 2.39: Live Frontend Overlay SEO Inspector** `[TT-008]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\Admin\FrontendSeoInspector`)
  - Admin-only shadow DOM inspector showing live canonical, meta, and OpenGraph data.
- [x] **Task 2.40: Live Schema Output health validator** `[TT-009]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\Schema\SchemaOutputValidator`)
  - Live client-side schema graph inspector with direct Rich Results test link.

---

## 🤖 Phase 3: Enterprise & AI-First
*Answer Engine Optimization (AEO) and scalable workspace controls.*
- [x] **Task 3.2: Automated `llms.txt` config generator** `[AI-001]` *(Priority: P1)*
  - *Status: Executed*
- [x] **Task 3.3: Chatbot Citation Tracker (Perplexity, ChatGPT, Gemini)** `[AI-002]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Ai\ChatbotCitationTracker`)
  - Tracks incoming referral user agents and AI citation mentions across major LLMs.
- [x] **Task 3.4: LLM Training Data Opt-Out Directives manager** `[AI-008]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\TechnicalSeo\RobotsTxtEditor`)
  - NoAI directives, TDM-Reservation HTTP headers, and robots.txt crawler opt-out rules.
- [x] **Task 3.5: Multi-Engine Keyword Rank Tracker** `[AR-002]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Analytics\KeywordRankTracker`)
  - Multi-engine SERP position logging with 180-day trend chart visualization.
- [x] **Task 3.6: Multi-Point Technical Audit checker** `[AR-003]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Admin\TechnicalSeoAuditEngine`)
  - 30-point technical audit covering SSL, canonicals, robots, 404s, mobile friendliness.
- [x] **Task 3.7: Granular Custom User Role SEO Manager permissions** `[UM-001]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Admin\CustomSeoUserRoles`)
  - Custom capabilities (`manage_holvek_seo`, `edit_holvek_meta`) for agency handoff.
- [ ] **Task 3.8: Native page builder editors SEO wrappers (Elementor, WPBakery)** `[IN-001]` *(Deferred after v1.0)*
  - Page-builder integration is intentionally out of scope for v1.0. It requires an approved compatibility matrix, dedicated UX, and end-to-end integration coverage before reintroduction.
- [x] **Task 3.9: Multisite network configuration manager** `[IN-003]` *(Priority: P1)*
  - *Status: Fully Executed* (`Holvek\Modules\Analytics\MultisiteNetworkSeo`)
  - Network-wide settings inheritance and cross-site audit synchronization.
  - Proactive scans detect legacy data and migrate with one-click REST execution.
- [x] **Task 3.11: Bulk Meta Title/Description Editor table** `[PM-005]` *(Priority: P1)*
  - *Status: Executed*
- [x] **Task 3.12: List views inline SEO Quick Editors** `[PM-006]` *(Priority: P1)*
  - *Status: Executed*
  - Color-coded SEO columns display real-time calculated health ratings, but inline form editing is pending.
- [ ] **Task 3.13: Chatbot Prompt Performance Audit checklists** `[AI-003]` *(Priority: P2)*
  - *Status: Pending* (Enterprise Add-on)
- [ ] **Task 3.14: Centralized Brand Knowledge Base profile** `[AI-004]` *(Priority: P2)*
  - *Status: Pending* (Enterprise Add-on)
- [ ] **Task 3.15: AI FAQs, Summaries, and Outlines generator** `[AI-005]` *(Priority: P2)*
  - *Status: Pending* (Enterprise Add-on)
- [ ] **Task 3.16: Competitive Citation Mention Gap engine** `[AI-007]` *(Priority: P2)*
  - *Status: Pending* (Enterprise Add-on)
- [ ] **Task 3.17: Semantic Cornerstone Keyword cluster planners** `[AI-009]` *(Priority: P2)*
  - *Status: Pending* (Enterprise Add-on)
- [ ] **Task 3.18: Automated PDF Performance client reports scheduler** `[AR-004]` *(Priority: P2)*
  - *Status: Pending* (Enterprise Add-on)
- [x] **Task 3.19: Keyword Rank Cannibalization detector** `[AR-005]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\Analytics\KeywordCannibalizationDetector`)
  - Discovers conflicting URLs competing for the same target keywords.
- [ ] **Task 3.20: Position Rank Drop trigger notifications** `[AR-006]` *(Priority: P2)*
  - *Status: Pending* (Enterprise Add-on)
- [x] **Task 3.21: PageSpeed Insights web vitals stats dashboard** `[AR-007]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\Analytics\PageSpeedDashboard`)
  - Core Web Vitals (LCP, FID/INP, CLS) performance dashboard.
- [ ] **Task 3.22: Agency Isolated Multi-Brand Workspace switcher** `[UM-002]` *(Priority: P2)*
  - *Status: Pending* (Agency Cloud Portal)
- [ ] **Task 3.23: Team SEO Comments, Tasks, & Approvals dashboard** `[UM-003]` *(Priority: P2)*
  - *Status: Pending* (Agency Cloud Portal)
- [x] **Task 3.24: System-wide Actions audit history logs** `[UM-004]` *(Priority: P2)*
  - *Status: Fully Executed* (`Holvek\Modules\Admin\SeoAuditHistoryLog`)
  - Immutable audit trail of all SEO metadata changes across users.
- [ ] **Task 3.25: Google Analytics 4 traffic integrations** `[IN-006]` *(Priority: P2)*
  - *Status: Pending* (SaaS Integration)
- [ ] **Task 3.26: Multi-provider AI Image prompt generator** `[AI-006]` *(Priority: P3)*
  - *Status: Pending*
- [ ] **Task 3.27: Enterprise Private BYO LLM custom models gateway** `[AI-010]` *(Priority: P3)*
  - *Status: Pending*
- [ ] **Task 3.28: Agency white-label Client Portal wrapper** `[UM-005]` *(Priority: P3)*
  - *Status: Pending*
- [ ] **Task 3.29: Content Editorial Workflow checks gatekeeper** `[UM-006]` *(Priority: P3)*
  - *Status: Pending*
- [ ] **Task 3.30: Automation triggers third-party hooks (Zapier, Make)** `[IN-005]` *(Priority: P3)*
  - *Status: Pending*
- [ ] **Task 3.31: Google Docs SEO synchronization browser extension** `[IN-007]` *(Priority: P3)*
  - *Status: Pending*
- [ ] **Task 3.32: Laravel Package integration framework setup** `[IN-008]` *(Priority: P3)*
  - *Status: Pending*
- [ ] **Task 3.33: Platform-agnostic shared core library extraction** `[PM-007]` *(Priority: P3)*
  - *Status: Pending*

---

## 🧠 Phase 4: Autonomous SEO & Search Intelligence
*Advanced self-remediation agents and semantic indexing.*

- [ ] **Task 4.1: Multi-Agent Autonomous Internal Linking workflows** `[AUTO-001]` *(Priority: P2)*
  - *Status: Pending (⚠️ Guardrails Required)*
  - *Note: Architectural Bottleneck Risk. Do not run heavy recursive LLM reasoning steps natively in single-threaded PHP. Offload agent linking cycles to external SaaS worker microservices.*
- [ ] **Task 4.2: Machine-Learning predictive keyword trend forecasting** `[AUTO-002]` *(Priority: P2)*
  - *Status: Deferred after v1.0.*
  - *Note: Google Trends scraping/token negotiation was removed from the plugin because it is not a stable, documented integration. Reconsider only with an approved provider, data-processing disclosure, service terms review, and automated integration tests.*
- [ ] **Task 4.3: Continuous AEO dynamic chatbot citation adaptation** `[AUTO-003]` *(Priority: P3)*
  - *Status: Pending*

---

## ⚠️ Architectural Guardrails & Core Technical Exclusions

Based on production-grade WordPress scaling patterns, the following tasks require strict guardrails or deferred off-server handling to protect client server resources:

### 1. Broken Link & Image Scanner `[TT-003]` (Deferred / SaaS Gated)
* **Risk Context**: Performing standard, recursive crawling loops natively on your WordPress origin server causes immediate CPU locks, runs into PHP script execution timeouts, and bloats `wp_options`. Furthermore, major hosting companies (WPEngine, Kinsta) ban internal link crawling plugins.
* **Mitigation**: 
  1. *Primary Strategy*: Offload crawling completely to a decoupled SaaS API service. The plugin registers a webhook to receive reports, rather than running recursive curl checks natively.
  2. *Fallback Strategy*: If local execution is forced, scans must be throttled to a maximum of 5 URL check pings per minute via low-priority `wp_schedule_single_event` triggers.

### 2. Autonomous Agent Linking Loops `[AUTO-001]` & Predictions `[AUTO-002]` (Decoupled execution)
* **Risk Context**: Running machine-learning pipelines or recursive multi-prompt GPT cycles in PHP freezes browser tabs, locks standard DB connections, and generates immediate browser 504 Gateway Timeouts.
* **Mitigation**: Decouple reasoning from PHP. The plugin strictly publishes data endpoints (like the structured `/schemamap` feed) and consumes pre-compiled agent decisions computed in safe cloud worker queues.

### 3. Editor Real-Time Assistant `[ACO-001]` (Debouncing / On-Demand UI)
* **Risk Context**: Dispatching API calls to LLM endpoints on every keystroke causes extreme billing costs for the user and floods the server connection gateway.
* **Mitigation**:
  1. Debounce Gutenberg client state changes by at least 3,000ms after a user halts typing before calling the assistant API.
  2. Maintain a prominent manual "Click to Analyze" button inside the React editor sidebar to ensure requests are primarily user-initiated.

### 4. Live Frontend Overlay SEO Inspector `[TT-008]` (Security Scope)
* **Risk Context**: Injecting script markers or diagnostic debuggers onto public-facing page loads exposes sensitive path details and can easily break third-party CDN / plugin static cache engines.
* **Mitigation**:
  1. Encapsulate the entire live overlay DOM cleanly within a sandboxed shadow root (`ShadowDOM`).
  2. Wrap initialization checks strictly within `current_user_can('edit_posts')` capability boundaries, preventing any visitor script load.

---
