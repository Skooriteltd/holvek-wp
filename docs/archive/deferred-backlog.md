# Holvek — Deferred Backlog (External API / SaaS Dependency)

> **Last updated:** September 2026
> **Status:** Implementation blocked on external service availability.
> When the external API / service is ready, move the relevant item into `backlog.md`, set status → **In Progress**, and follow the integration contract below.

---

## BL-039 · Schema Import from Competitor URL

**Tier:** 3 | **Spec:** `ASD-003` | **Blocked by:** Web scraping microservice

### Why deferred
Scraping a competitor URL from inside a WordPress plugin creates CFAA exposure, hits server-side firewall blocks, and cannot handle JS-rendered pages. Requires an external scraping microservice.

### External service contract
```
POST https://api.holvek.io/v1/schema/extract
Authorization: Bearer {holvek_api_key}
Body:   { "url": "https://competitor.com/page" }
Response: { "@context": "https://schema.org", "@type": "...", ... }
```

### Plugin integration point
- **File:** `src/Modules/Schema/CompetitorScraper.php` (already exists — add `fetchRemoteSchema(string $url): array`)
- **REST:** `POST /holvek/v1/schema/import-competitor`
- **Settings key:** `holvek_schema_api_key`

### Acceptance criteria
- [ ] User pastes competitor URL; plugin POSTs to microservice
- [ ] Returned JSON-LD pre-populates post schema override field
- [ ] User reviews and save/discards before publishing
- [ ] 4xx/5xx from service shown as user-facing admin notice

---

## BL-040 · AI Visibility Tracker

**Tier:** 4 | **Spec:** `AI-002` | **Blocked by:** AI citation monitoring API

### Why deferred
No public API exists to query ChatGPT, Perplexity, or Gemini for brand mentions at scale inside a WP request cycle. Requires a standing cloud worker that webhooks results back.

### External service contract
```
// Cloud worker POSTs to plugin webhook
POST https://{site}/wp-json/holvek/v1/ai-visibility/webhook
X-Holvek-Signature: {hmac_sha256}
Body: {
  "keyword": "best SEO plugin",
  "engine": "perplexity|chatgpt|gemini",
  "cited": true,
  "citation_url": "https://yoursite.com/page",
  "position": 2,
  "snippet": "...",
  "checked_at": "2026-09-17T12:00:00Z"
}
```

### Plugin integration point
- **New file:** `src/Modules/Analytics/AiVisibilityTracker.php`
- **REST:** `POST /holvek/v1/ai-visibility/webhook` (webhook receiver), `GET /holvek/v1/ai-visibility/report`
- **DB table:** `holvek_ai_visibility` (keyword, engine, cited, citation_url, position, checked_at)
- **Settings keys:** `holvek_ai_visibility_api_key`, `holvek_ai_visibility_webhook_secret`

### Acceptance criteria
- [ ] HMAC-SHA256 signature verified before storing webhook data
- [ ] Dashboard widget: citations per keyword per LLM engine (30/90d trend)
- [ ] Alert when citation count drops (email + admin notice)
- [ ] `GET /holvek/v1/ai-visibility/report?days=30` returns trend data

---

## BL-041 · Multi-Brand Agency Workspaces

**Tier:** 4 | **Spec:** `UM-002` | **Blocked by:** Multi-tenant SaaS dashboard

### Why deferred
Isolated SEO environments per client brand require tenant isolation at the data layer, impossible with a single WordPress database without a dedicated SaaS routing layer.

### External service contract
```
POST https://{site}/wp-json/holvek/v1/workspaces/sync
X-Agency-Token: {jwt}
Body: { "workspace_id": "ws_abc123", "brand_name": "Acme", "settings": { ... } }
```

### Plugin integration point
- **New file:** `src/Modules/Admin/AgencyWorkspaceManager.php`
- **REST:** `GET /holvek/v1/workspaces`, `POST /holvek/v1/workspaces/sync`
- **Settings keys:** `holvek_agency_token`, `holvek_workspace_id`

### Acceptance criteria
- [ ] Plugin authenticates against SaaS dashboard via JWT
- [ ] Each workspace maps to isolated option prefixes
- [ ] Workspace switcher in admin sidebar
- [ ] Per-workspace audit history, keyword sets, redirect rules

---

## BL-045 · Laravel Package Foundation

**Tier:** 4 | **Spec:** `IN-008` | **Blocked by:** Separate composer package repository

### Why deferred
Extracting core SEO logic into a framework-agnostic package requires a standalone repo, PHPUnit test suite, and Packagist release — it must not be coupled to WordPress hooks.

### Plugin integration point
- **Composer:** `"holvek/seo-core": "^1.0"` added to `composer.json`
- **Files to extract (pure logic only):** `MetaTagsGenerator`, `SchemaGenerator`, `SitemapPriorityManager`
- **Adapter:** `src/Core/WordPressAdapter.php` maps WP hooks → service provider events

### Acceptance criteria
- [ ] `composer require holvek/seo-core` installs without WordPress loaded
- [ ] No `get_post_meta` / `add_action` calls in the package
- [ ] 80%+ PHPUnit coverage; semantic versioning; separate changelog
- [ ] WordPress plugin wraps via adapter with zero logic duplication

---

## BL-046 · BYO Enterprise LLM Gateway

**Tier:** 4 | **Spec:** `AI-010` | **Blocked by:** Enterprise private model endpoint

### Why deferred
Enterprise clients run private LLM endpoints (Azure OpenAI, self-hosted Llama, Vertex AI) with custom VPC routing and compliance requirements that cannot be generalised into a plugin setting.

### External service contract
```
// All AI calls route to customer endpoint (OpenAI-compatible interface)
POST {holvek_enterprise_llm_endpoint}
Authorization: {holvek_enterprise_llm_auth_header}
Body: { "model": "...", "messages": [...], "max_tokens": N }
Response: OpenAI-compatible chat completion object
```

### Plugin integration point
- **File:** `src/Modules/Ai/ModelGateway.php` (already exists — add `enterprise` branch to `getEndpoint(): string`)
- **Settings keys:** `holvek_enterprise_llm_endpoint`, `holvek_enterprise_llm_auth_header`, `holvek_enterprise_llm_model`
- **Feature flag:** `holvek_ai_provider = 'enterprise'`
- **New REST:** `POST /holvek/v1/ai/test-connection`

### Acceptance criteria
- [ ] When provider = `enterprise`, all LLM calls route to configured endpoint
- [ ] Connection test button in settings
- [ ] Falls back to public provider on 5xx
- [ ] Audit log records which provider handled each request

---

## BL-047 · Team SEO Comments & Approval Workflows

**Tier:** 4 | **Spec:** `UM-003` | **Blocked by:** Real-time collaboration SaaS layer

### Why deferred
Threaded SEO comments, task assignment, and publish gating require real-time presence and cross-user state management beyond WordPress's synchronous request model.

### External service contract
```
// SaaS → Plugin: new comment
POST https://{site}/wp-json/holvek/v1/workflow/comment
Body: { "post_id": 123, "user_id": 5, "comment": "Add focus keyword in H2", "resolved": false }

// SaaS → Plugin: approval decision
POST https://{site}/wp-json/holvek/v1/workflow/approval
Body: { "post_id": 123, "approved": true, "reviewer_id": 2 }
```

### Plugin integration point
- **New file:** `src/Modules/Admin/SeoWorkflowManager.php`
- **REST:** `POST /workflow/comment`, `POST /workflow/approval`, `GET /workflow/status/{post_id}`
- **Meta keys:** `_holvek_seo_approved`, `_holvek_workflow_comments`
- **Gate:** `pre_post_update` blocks publish if `_holvek_seo_approved !== 'yes'` and workflow enabled

### Acceptance criteria
- [ ] Comments visible as meta-box thread in post editor
- [ ] Post cannot publish until all SEO comments resolved (optional gate)
- [ ] Reviewer notified by email on new comment
- [ ] Approval status visible in post list column

---

## BL-048 · Client Reporting Portal

**Tier:** 4 | **Spec:** `UM-005` | **Blocked by:** White-label SaaS dashboard

### Why deferred
A white-label portal requires user auth outside WordPress, custom domain routing, PDF generation, and data aggregation across multiple client sites — a SaaS product in itself.

### External service contract
```
// SaaS portal polls this endpoint
GET https://{site}/wp-json/holvek/v1/reports/summary
Authorization: Bearer {holvek_reporting_token}
Response: {
  "period": "2026-09",
  "seo_score": 84,
  "indexed_pages": 142,
  "top_keywords": [...],
  "audit_issues": { "critical": 2, "warning": 8, "pass": 34 },
  "rank_changes": [...],
  "last_updated": "2026-09-17T12:00:00Z"
}
```

### Plugin integration point
- **New file:** `src/Modules/Api/ReportingApiEndpoint.php`
- **REST:** `GET /holvek/v1/reports/summary` (token-authenticated, read-only)
- **Settings keys:** `holvek_reporting_token`, `holvek_reporting_portal_url`
- **Data sources:** `TechnicalSeoAuditEngine` + `KeywordRankTracker` + `GoogleSearchConsoleIntegration` + `PageSpeedDashboard`

### Acceptance criteria
- [ ] Token-authenticated read-only snapshot endpoint
- [ ] Admin can regenerate token
- [ ] Rate limiting: 60 requests/hour per token
- [ ] All 4 data sources contribute to the snapshot

---

## BL-049 · Broken Link Scanner

**Tier:** 4 | **Spec:** `TT-003` | **Blocked by:** External headless crawl microservice

### Why deferred
A native broken link crawler inside WordPress would exhaust memory, hit PHP timeouts, and be rate-limited on external URLs. Must run as a headless cloud crawler.

### External service contract
```
// Plugin registers site
POST https://api.holvek.io/v1/crawler/register
Body: { "site_url": "https://yoursite.com", "webhook_url": "https://yoursite.com/wp-json/holvek/v1/broken-links/webhook" }

// Crawler webhooks results
POST https://{site}/wp-json/holvek/v1/broken-links/webhook
X-Holvek-Signature: {hmac_sha256}
Body: { "source_url": "...", "broken_url": "...", "status_code": 404, "anchor_text": "...", "found_at": "..." }
```

### Plugin integration point
- **New file:** `src/Modules/TechnicalSeo/BrokenLinkManager.php`
- **REST:** `POST /broken-links/webhook`, `GET /broken-links`, `POST /broken-links/dismiss/{id}`
- **DB table:** `holvek_broken_links` (source_url, broken_url, status_code, anchor_text, resolved, found_at)
- **Settings keys:** `holvek_crawler_api_key`, `holvek_crawler_webhook_secret`

### Acceptance criteria
- [ ] Admin table lists all broken links (source page, anchor text, HTTP status)
- [ ] One-click dismiss per row; bulk dismiss selection
- [ ] HMAC-SHA256 webhook verification before storing
- [ ] Weekly crawl schedule configurable

---

## BL-050 · Autonomous Internal Linking Agent

**Tier:** 4 | **Spec:** `AUTO-001` | **Blocked by:** Cloud worker / async LLM pipeline

### Why deferred
Autonomously inserting internal links requires a cloud worker with full site content indexing and LLM access — impossible to run synchronously in a WP plugin request at scale.

### External service contract
```
// Plugin serves content for the worker to index
GET https://{site}/wp-json/holvek/v1/auto-linker/content-index
Authorization: Bearer {holvek_auto_linker_token}
Response: [{ "post_id": 1, "url": "...", "title": "...", "keyword": "...", "content_hash": "..." }]

// Worker webhooks link insertion suggestions
POST https://{site}/wp-json/holvek/v1/auto-linker/suggestions
X-Holvek-Signature: {hmac_sha256}
Body: { "post_id": 42, "insertions": [{ "anchor_text": "technical SEO", "target_url": "/guide/", "paragraph_index": 3 }] }
```

### Plugin integration point
- **New file:** `src/Modules/ContentAssistant/AutoLinkerEndpoints.php`
- **REST:** `GET /auto-linker/content-index`, `POST /auto-linker/suggestions`, `GET /auto-linker/suggestions/{post_id}`, `POST /auto-linker/approve/{id}`
- **Settings keys:** `holvek_auto_linker_token`, `holvek_auto_linker_webhook_secret`, `holvek_auto_linker_auto_approve`
- **Meta key:** `_holvek_auto_link_suggestions` (JSON pending insertions)

### Acceptance criteria
- [ ] Token-authenticated content index endpoint
- [ ] Webhook receiver validates HMAC and stores suggestions as pending
- [ ] Editor sees pending suggestions per post with approve/reject per suggestion
- [ ] Auto-approve mode (opt-in) applies on receive
- [ ] All auto-inserted links tagged `data-holvek-auto-link="true"` for auditing

---

*Total deferred: 9 items (BL-039, 040, 041, 045, 046, 047, 048, 049, 050)*
*All other 41 backlog items: ✅ Implemented and production-ready*
