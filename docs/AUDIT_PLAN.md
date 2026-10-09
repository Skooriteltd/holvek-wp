# Holvek Plugin — Security, Performance, Compliance & UX Audit Plan

> **Created:** 2026-09-24
> **Scope:** Full codebase audit — 29 issues across security, performance, compliance, data integrity, and UX.
> **Status:** Awaiting execution

---

## Execution Rules

1. **No issue may be marked DONE unless every listed acceptance criterion passes.**
2. **Each fix must be verified with the exact command or manual step listed.**
3. **Fixes must not introduce regressions** — existing PHPUnit tests must still pass after each change.
4. **Changes must be scoped** — do not refactor unrelated code in the same commit.
5. **Each component section is independently deployable** — fix and verify one component at a time.

---

## Component 1 — Security Fixes

### SEC-01 · XSS: Breadcrumb helper echoes unescaped HTML

| Field | Value |
|-------|-------|
| **Severity** | CRITICAL |
| **File** | `holvek.php` · Line 91 |
| **Root Cause** | `echo $renderer->render()` outputs HTML containing user-controlled data (post titles, term names) without escaping. |

**Current code:**

```php
function holvek_breadcrumbs(): void {
    $renderer = new \Holvek\Modules\Breadcrumbs\BreadcrumbRenderer();
    echo $renderer->render();
}
```

**Required fix:**

Ensure every dynamic value inside `BreadcrumbRenderer::render()` is escaped at render time. The outer call should use `wp_kses_post()` as a defense-in-depth layer:

```php
function holvek_breadcrumbs(): void {
    $renderer = new \Holvek\Modules\Breadcrumbs\BreadcrumbRenderer();
    echo wp_kses_post( $renderer->render() );
}
```

Additionally, audit `BreadcrumbRenderer::render()` and verify every post title, term name, and URL is escaped with `esc_html()` / `esc_url()` before concatenation.

**Acceptance criteria:**

- [ ] `holvek_breadcrumbs()` wraps output in `wp_kses_post()`.
- [ ] Inside `BreadcrumbRenderer::render()`, every `$title` / `$name` uses `esc_html()`, every `$url` / `$href` uses `esc_url()`.
- [ ] Create a post with the title `<img src=x onerror=alert(1)>`, call `holvek_breadcrumbs()` on the frontend, and confirm the XSS payload is rendered as harmless escaped text — not as an HTML tag.
- [ ] `composer run phpcs -- src/Modules/Breadcrumbs/BreadcrumbRenderer.php holvek.php` passes with no escaping violations.

---

### SEC-02 · XSS: Frontend Inspector inline JS with unescaped variables

| Field | Value |
|-------|-------|
| **Severity** | CRITICAL |
| **File** | `src/Modules/Admin/FrontendSeoInspector.php` · Lines 141–199 |
| **Root Cause** | PHP variables interpolated directly into inline `<script>` block; response data injected into DOM via `innerHTML` without escaping. |

**Required fix:**

1. Replace the inline `<script>` block with a properly enqueued JS file.
2. Pass `$apiBase`, `$nonce`, and `$postId` via `wp_localize_script()` or `wp_add_inline_script()` with `wp_json_encode()`.
3. In the JS template, replace `title="${v.message}"` with a text-node approach or escape the attribute value.

**Acceptance criteria:**

- [ ] No raw `<?php echo` appears inside any `<script>` tag in the file.
- [ ] Data is passed to JS via `wp_localize_script()` or `wp_add_inline_script()` with `wp_json_encode()`.
- [ ] The inspector panel renders correctly on a singular post for a logged-in editor.
- [ ] Injecting `" onmouseover="alert(1)` into a meta field's value does not execute JS when the inspector panel is opened.
- [ ] `composer run phpcs -- src/Modules/Admin/FrontendSeoInspector.php` passes.

---

### SEC-03 · Inline style tag bypasses WordPress enqueue system

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/Admin/SeoScoreColumn.php` · Line 164 |
| **Root Cause** | `echo '<style>...'` in `admin_head` instead of using `wp_add_inline_style()`. |

**Required fix:**

Register a minimal admin stylesheet (or attach to WordPress's built-in `list-tables` style) and use `wp_add_inline_style()`:

```php
public function injectColumnStyles(): void {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, array( 'edit-post', 'edit-page' ), true ) ) {
        return;
    }
    wp_add_inline_style( 'list-tables', '.column-holvek_seo { width: 60px; text-align: center; }' );
}
```

**Acceptance criteria:**

- [ ] No `echo '<style>'` remains in the file.
- [ ] Column styling still applies on both `edit-post` and `edit-page` screens.
- [ ] `composer run phpcs -- src/Modules/Admin/SeoScoreColumn.php` passes.

---

### SEC-04 · Inline script block in upgrade banner

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/Admin/UpgradePromptManager.php` · Lines 100–114 |
| **Root Cause** | `echo '<script>...'` with nonce value embedded — violates WordPress coding standards and may break under strict CSP. |

**Required fix:**

Extract the dismiss handler into a small enqueued JS file (e.g., `build/upgrade-dismiss.js`) or use `wp_add_inline_script()` attached to a dependency. Pass the nonce and AJAX URL via `wp_localize_script()`.

**Acceptance criteria:**

- [ ] No `echo '<script>'` remains in the file.
- [ ] Clicking the dismiss button on the upgrade banner still fires the AJAX call and persists the dismissal in user meta.
- [ ] Refreshing the page after dismissal does not show the banner again.
- [ ] `composer run phpcs -- src/Modules/Admin/UpgradePromptManager.php` passes.

---

### SEC-05 · ReDoS via user-controlled regex patterns on frontend

| Field | Value |
|-------|-------|
| **Severity** | HIGH |
| **File** | `src/Modules/TechnicalSeo/RedirectManager.php` · Lines 44–51 |
| **Root Cause** | Admin-submitted regex patterns are compiled and executed on every frontend request via `@preg_match()` with no complexity guard. The `@` suppresses errors. |

**Required fix:**

1. On save (`addRedirectEndpoint`), validate the regex by test-compiling it and rejecting patterns that fail a backtracking safety check.
2. On match (`handleRedirects`), replace `@preg_match` with a guarded version that temporarily lowers `pcre.backtrack_limit` and checks the return value.
3. Remove `@` suppressor; log invalid patterns via `error_log()` in debug mode.

```php
// Guarded match with backtrack limit
$previousLimit = ini_get( 'pcre.backtrack_limit' );
ini_set( 'pcre.backtrack_limit', '10000' );
$result = preg_match( $pattern, $currentPath );
ini_set( 'pcre.backtrack_limit', $previousLimit );

if ( $result === false ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[Holvek] Invalid redirect regex: ' . $pattern );
    }
    continue;
}
```

**Acceptance criteria:**

- [ ] `@preg_match` is replaced with a backtrack-guarded `preg_match` (explicit return-value check).
- [ ] No `@` error suppressor on any `preg_match` or `preg_replace` call in the file.
- [ ] A regex redirect rule with a catastrophic backtracking pattern (e.g., `(a+)+$`) does not hang a frontend request — it skips the rule and continues.
- [ ] Valid regex redirects (e.g., `^/old-blog/(.*)$` → `/blog/$1`) still work correctly.
- [ ] `composer run phpcs -- src/Modules/TechnicalSeo/RedirectManager.php` passes.

---

### SEC-06 · Unbounded regex rules query on frontend

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/TechnicalSeo/RedirectManager.php` · Lines 100–101 |
| **Root Cause** | `SELECT ... WHERE is_regex = 1` with no `LIMIT`. |

**Required fix:**

Add `LIMIT 500` to the query. Log a warning if 500 rules are returned.

**Acceptance criteria:**

- [ ] The SQL query includes `LIMIT 500`.
- [ ] A site with 1000 regex redirect rules does not load all 1000 into memory on a frontend request.
- [ ] When the limit is hit, a debug log entry is written (guarded by `WP_DEBUG`).

---

### SEC-07 · Missing open-redirect validation in 404-to-redirect creation

| Field | Value |
|-------|-------|
| **Severity** | HIGH |
| **File** | `src/Modules/TechnicalSeo/ErrorMonitor.php` · Lines 212–282 |
| **Root Cause** | `createRedirectFromLog` sanitizes the target with `sanitize_text_field()` but never validates it as a safe local URL. An admin or CSRF attack could create an open redirect. |

**Required fix:**

Add target validation before inserting:

```php
$redirectManager = new RedirectManager();
if ( ! $redirectManager->isSafeTarget( $target, false ) ) {
    return new \WP_Error( 'unsafe_target', __( 'Redirect target must be a local path or URL on this site.', 'holvek' ), array( 'status' => 400 ) );
}
```

Note: `isSafeTarget` is currently `private`. Change it to `public` or extract a shared utility.

**Acceptance criteria:**

- [ ] Attempting to create a redirect from a 404 log with `target=https://evil.com` returns a 400 error with an `unsafe_target` code.
- [ ] Creating a redirect with `target=/about-us` succeeds.
- [ ] Creating a redirect with `target=https://this-site.com/about-us` (matching the site's own host) succeeds.
- [ ] The `isSafeTarget` method is accessible to `ErrorMonitor` without duplication.

---

### SEC-08 · Settings export leaks encrypted API keys

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/Admin/AdminModule.php` · Lines 1489–1520 |
| **Root Cause** | The `$optionKeys` array includes `holvek_openai_key`, `holvek_anthropic_key`, and `holvek_psi_key` despite the inline comment stating they are excluded. |

**Required fix:**

Remove these three keys from the `$optionKeys` array in `exportSettings()`:

```diff
- 'holvek_openai_key',
- 'holvek_anthropic_key',
  ...
- 'holvek_psi_key',
```

**Acceptance criteria:**

- [ ] `GET /holvek/v1/settings/export` response does not contain `holvek_openai_key`, `holvek_anthropic_key`, or `holvek_psi_key` fields.
- [ ] Non-sensitive settings (e.g., `holvek_breadcrumb_separator`) are still present in the export.
- [ ] The import endpoint still functions correctly (these keys were already excluded from the import whitelist).

---

## Component 2 — Performance Fixes

### PERF-01 · addCapabilities() writes to DB on every request

| Field | Value |
|-------|-------|
| **Severity** | CRITICAL |
| **File** | `src/Modules/Admin/CustomSeoUserRoles.php` · Line 74 |
| **Root Cause** | `add_action( 'init', ... 'addCapabilities' )` runs `get_role()->add_cap()` on every request. `add_cap()` calls `update_option()` on the roles option every time. |

**Required fix:**

Remove the `init` hook entirely. Capabilities are already applied during activation via `Plugin::activate()`. If a self-heal mechanism is needed, gate it behind a one-time version check:

```php
public function register(): void {
    add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );

    // One-time recovery: re-apply capabilities if the stored version is stale.
    $applied = get_option( 'holvek_caps_version', '' );
    if ( $applied !== HOLVEK_VERSION ) {
        self::addCapabilities();
        update_option( 'holvek_caps_version', HOLVEK_VERSION, true );
    }
}
```

**Acceptance criteria:**

- [ ] The line `add_action( 'init', array( static::class, 'addCapabilities' ) )` is removed.
- [ ] After activating the plugin on a fresh site, the administrator role has all 4 SEO capabilities.
- [ ] Loading the homepage 10 times in a row does NOT produce 10 `UPDATE wp_options SET option_value = ... WHERE option_name = 'wp_user_roles'` queries. Verify with Query Monitor plugin or `$wpdb->queries`.
- [ ] After upgrading `HOLVEK_VERSION`, capabilities are re-applied once automatically.

---

### PERF-02 · All modules instantiated on every request

| Field | Value |
|-------|-------|
| **Severity** | HIGH |
| **File** | `src/Plugin.php` · Lines 298–510 |
| **Root Cause** | `bootModules()` instantiates and registers 50+ module classes unconditionally. Admin-only modules load on the frontend; frontend-only modules load in admin; WooCommerce modules load without WooCommerce. |

**Required fix:**

Group module boot calls into conditional blocks:

```php
private function bootModules(): void {
    // Always-on modules (SEO output, redirects, sitemap routes)
    $this->bootCoreModules();

    if ( is_admin() ) {
        $this->bootAdminModules();
    }

    if ( ! is_admin() ) {
        $this->bootFrontendModules();
    }

    // Deferred: REST-only modules registered via rest_api_init
    add_action( 'rest_api_init', array( $this, 'bootRestModules' ) );

    // Conditional integrations
    if ( class_exists( 'WooCommerce' ) ) {
        $this->bootWooCommerceModules();
    }
}
```

**Acceptance criteria:**

- [ ] Frontend requests do not instantiate admin-only modules (`AdminModule`, `SetupWizard`, `MigrationManager`, `BulkMetaEditor`, `TechnicalSeoAuditEngine`, `UpgradePromptManager`, `SeoAuditHistoryLog`, `CustomSeoUserRoles`, `FrontendSeoInspector` [rename needed — this IS frontend]).
- [ ] Admin requests do not instantiate frontend-only modules (`CookieBannerModule` frontend assets, `BreadcrumbRenderer` output).
- [ ] `WooCommerceProductSchema` is not instantiated when WooCommerce is not active.
- [ ] All REST endpoints still respond correctly when called.
- [ ] SEO meta tags, canonical URLs, robots meta, and sitemaps still render correctly on the frontend.

---

### PERF-03 · Orphan pages endpoint loads 1000 posts with N+5 queries

| Field | Value |
|-------|-------|
| **Severity** | HIGH |
| **File** | `src/Modules/Admin/AdminModule.php` · Lines 983–1017 |
| **Root Cause** | `'posts_per_page' => 1000` followed by per-post calls to `get_permalink()`, `get_the_title()`, etc. |

**Required fix:**

1. Reduce default to 100 posts per page.
2. Accept `page` and `per_page` query parameters.
3. Use `update_post_caches()` to batch-prime post caches.

**Acceptance criteria:**

- [ ] The endpoint accepts `?page=1&per_page=50` parameters.
- [ ] Default `per_page` is 100, maximum is 200.
- [ ] Response includes `total`, `total_pages`, and `page` fields.
- [ ] On a site with 5000 posts, the endpoint responds in under 3 seconds with the default page size.
- [ ] The transient cache still functions correctly.

---

### PERF-04 · getRedirectRules loads all rows without pagination

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/TechnicalSeo/RedirectManager.php` · Line 128 |
| **Root Cause** | `SELECT * FROM $table` with no LIMIT or pagination. |

**Required fix:**

Add `page` and `per_page` parameters to the REST endpoint with defaults of 1 and 100. Apply `LIMIT %d OFFSET %d` to the SQL query. Return total count in response.

**Acceptance criteria:**

- [ ] `GET /holvek/v1/redirects` accepts `page` and `per_page` query params.
- [ ] Default returns at most 100 rows.
- [ ] Response includes `total` and `total_pages` fields.
- [ ] Legacy migration from `wp_options` still triggers on first load when the table is empty.

---

### PERF-05 · Audit log runs COUNT+DELETE on every meta write

| Field | Value |
|-------|-------|
| **Severity** | HIGH |
| **File** | `src/Modules/Admin/SeoAuditHistoryLog.php` · Lines 224–231 |
| **Root Cause** | Every call to `log()` executes `SELECT COUNT(*)` and potentially a `DELETE` against the audit table. Bulk operations multiply this. |

**Required fix:**

Prune via a scheduled cron job instead of inline:

```php
public function register(): void {
    add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
    $this->hookMetaChanges();

    // Schedule daily pruning instead of per-write pruning
    if ( ! wp_next_scheduled( 'holvek_prune_audit_log' ) ) {
        wp_schedule_event( time(), 'daily', 'holvek_prune_audit_log' );
    }
    add_action( 'holvek_prune_audit_log', array( $this, 'pruneOldEntries' ) );
}
```

Remove the COUNT+DELETE block from the `log()` method.

**Acceptance criteria:**

- [ ] The `log()` method no longer contains `SELECT COUNT(*)` or `DELETE` queries.
- [ ] A cron hook `holvek_prune_audit_log` is scheduled daily.
- [ ] After saving 25 posts via BulkMetaEditor, Query Monitor shows zero `SELECT COUNT(*) FROM wp_holvek_audit_log` queries during the request.
- [ ] The audit log table does not grow unboundedly — after the daily cron runs, rows exceeding `LOG_MAX_ROWS` are removed.
- [ ] The cron hook is cleared on deactivation (add to `clearScheduledWork()`).

---

### PERF-06 · Audit results stored in autoloaded option

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/Admin/TechnicalSeoAuditEngine.php` · Line 150 |
| **Root Cause** | `update_option()` without explicit `autoload = false`. Large serialized results are loaded on every page. |

**Required fix:**

```php
update_option( self::RESULTS_OPTION, $results, false );
```

Also apply to `PROGRESS_OPTION` on line 458.

**Acceptance criteria:**

- [ ] Both `update_option` calls pass `false` as the third argument.
- [ ] After running an audit, `SELECT autoload FROM wp_options WHERE option_name = 'holvek_audit_results'` returns `no`.
- [ ] The audit results REST endpoint still returns data correctly.

---

## Component 3 — WordPress.org Compliance Fixes

### COMP-01 · Non-translatable fallback meta description strings

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/Seo/MetaTagsGenerator.php` · Lines 99–164 |
| **Root Cause** | Five `sprintf()` calls with hardcoded English strings. |

**Required fix:**

Wrap each format string in `__()`:

```php
/* translators: 1: term name, 2: taxonomy label, 3: site name */
return sprintf(
    __( 'Browse all posts in the "%1$s" %2$s on %3$s.', 'holvek' ),
    $term->name,
    $term->taxonomy,
    get_bloginfo( 'name' )
);
```

Apply to all five instances (lines 100, 117, 132, 144, 161).

**Acceptance criteria:**

- [ ] All five `sprintf()` calls use `__( '...', 'holvek' )` for the format string.
- [ ] Each has a `/* translators: ... */` comment above it.
- [ ] `wp i18n make-pot . languages/holvek.pot` (or `composer run makepot`) includes all five new strings.
- [ ] Meta descriptions still render correctly on category, author, post-type, search, and date archive pages.

---

### COMP-02 · Welcome notice strings not translatable

| Field | Value |
|-------|-------|
| **Severity** | HIGH |
| **File** | `src/Modules/Onboarding/SetupWizard.php` · Lines 48–58 |
| **Root Cause** | Direct `echo` of hardcoded English HTML strings without `__()` or `esc_html__()`. |

**Required fix:**

Use `printf` with escaped translatable strings:

```php
printf(
    '<p style="margin:0;font-size:15px;font-weight:600;color:#0f172a;">%s</p>',
    esc_html__( 'Welcome to Holvek!', 'holvek' )
);
printf(
    '<p style="margin:4px 0 0;color:#475569;">%s <a href="%s" style="color:#3b82f6;font-weight:500;">%s</a> %s</p>',
    esc_html__( 'Your SEO is already working with smart defaults.', 'holvek' ),
    esc_url( $setupUrl ),
    esc_html__( 'Complete the 30-second setup', 'holvek' ),
    esc_html__( 'to customize site type and import existing SEO data.', 'holvek' )
);
```

**Acceptance criteria:**

- [ ] No hardcoded English user-facing strings remain without `__()` / `esc_html__()`.
- [ ] All dynamic values in HTML attributes use `esc_attr()` or `esc_url()`.
- [ ] The notice renders correctly and is dismissible.
- [ ] `composer run phpcs -- src/Modules/Onboarding/SetupWizard.php` passes.
- [ ] POT file includes the new translatable strings.

---

### COMP-03 · Import success message not translatable

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/Admin/AdminModule.php` · Line 1600 |

**Required fix:**

```php
'message' => sprintf(
    /* translators: %d: number of imported settings */
    __( 'Imported %d settings successfully. API keys and credentials are excluded from import for security.', 'holvek' ),
    $imported
),
```

**Acceptance criteria:**

- [ ] The string uses `sprintf( __( ... ) )` with a translators comment.
- [ ] POST to `/holvek/v1/settings/import` returns the translatable message.

---

### COMP-04 · REST error messages not translatable

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **Files** | `BulkMetaEditor.php:120`, `ErrorMonitor.php:221`, `RedirectManager.php:391,421,446,520` |

**Required fix:**

Wrap each error string in `__( '...', 'holvek' )`.

**Acceptance criteria:**

- [ ] Every user-facing string passed to `WP_Error` or `WP_REST_Response` in the listed files uses `__()`.
- [ ] `grep -rn "WP_Error\|'message'" <file>` shows no unwrapped English strings (excluding internal error codes).

---

### COMP-05 · Cron hook lists out of sync between uninstall and deactivate

| Field | Value |
|-------|-------|
| **Severity** | LOW |
| **Files** | `uninstall.php:22-35`, `src/Plugin.php:605-619` |
| **Root Cause** | Three hooks present in `Plugin::clearScheduledWork()` are missing from `uninstall.php`. |

**Required fix:**

Add the missing hooks to `uninstall.php`:
- `holvek_flush_404_buffer`
- `holvek_cornerstone_staleness_check`
- `holvek_weekly_usage_email`

Also add the new `holvek_prune_audit_log` hook (from PERF-05 fix).

**Acceptance criteria:**

- [ ] The cron hook arrays in `uninstall.php` and `Plugin::clearScheduledWork()` contain the same set of hooks.
- [ ] After uninstalling the plugin, `wp_get_scheduled_event('holvek_flush_404_buffer')` returns `false`.

---

## Component 4 — Data Integrity & Bug Fixes

### BUG-01 · Duplicate entries in KeyVault sensitiveKeys()

| Field | Value |
|-------|-------|
| **Severity** | LOW |
| **File** | `src/Core/Security/KeyVault.php` · Lines 155–158 |

**Required fix:**

Remove the two duplicate array entries.

**Acceptance criteria:**

- [ ] `sensitiveKeys()` returns exactly 8 unique keys (not 10).
- [ ] Encryption and decryption of all credential types still work.

---

### BUG-02 · JWT base64 encoding not URL-safe for GA4 OAuth

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/Admin/AdminModule.php` · Lines 1419–1450 |
| **Root Cause** | Standard `base64_encode()` used for JWT components. RFC 7519 requires URL-safe base64. |

**Required fix:**

Create a helper and use it for header, payload, and signature:

```php
private function base64UrlEncode( string $data ): string {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}
```

Apply to lines 1419–1420 (header), 1427–1437 (payload), and 1450 (signature).

**Acceptance criteria:**

- [ ] All three JWT components use URL-safe base64 encoding.
- [ ] The GA4 test-connection endpoint successfully obtains an access token with valid credentials.
- [ ] The encoded JWT contains no `+`, `/`, or trailing `=` characters.

---

### BUG-03 · Migration loads unbounded rows into memory

| Field | Value |
|-------|-------|
| **Severity** | HIGH |
| **File** | `src/Modules/Migration/MigrationManager.php` · Lines 120–125 |
| **Root Cause** | `SELECT post_id, meta_value FROM postmeta WHERE meta_key = %s` with no LIMIT. 8 meta keys × N posts. |

**Required fix:**

Process in batches of 500:

```php
$batchSize = 500;
$offset    = 0;

do {
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value != ''
             LIMIT %d OFFSET %d",
            $sourceKey,
            $batchSize,
            $offset
        )
    );

    foreach ( $rows as $row ) {
        // ... existing per-row logic ...
    }

    $offset += $batchSize;
} while ( count( $rows ) === $batchSize );
```

**Acceptance criteria:**

- [ ] The SQL query includes `LIMIT %d OFFSET %d`.
- [ ] Memory usage stays below 128 MB when migrating from a Yoast installation with 50,000 posts (test with a fixture dataset or monitor via `memory_get_peak_usage()`).
- [ ] Final migration counts (migrated + skipped) match the total number of source meta rows.
- [ ] Existing migration test (if any) still passes.

---

### BUG-04 · MySQL subquery limitation in 404 log pruning

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/TechnicalSeo/ErrorMonitor.php` · Lines 151–160 |
| **Root Cause** | Nested subquery with `LIMIT` in `DELETE ... NOT IN (SELECT ... LIMIT)` is unreliable across MySQL versions. |

**Required fix:**

Two-step approach:

```php
$keepIds = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT id FROM {$table} ORDER BY last_hit DESC LIMIT %d",
        self::MAX_LOGS
    )
);

if ( ! empty( $keepIds ) ) {
    $placeholders = implode( ',', array_fill( 0, count( $keepIds ), '%s' ) );
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table} WHERE id NOT IN ($placeholders)",
            ...$keepIds
        )
    );
}
```

**Acceptance criteria:**

- [ ] No nested subquery remains in the DELETE statement.
- [ ] After inserting 150 log entries and running `flushBufferToDatabase()`, only 100 remain (matching `MAX_LOGS`).
- [ ] The 100 retained entries are the most recent by `last_hit`.
- [ ] Works correctly on MySQL 5.7, 8.0, and MariaDB 10.x (verify by checking the SQL syntax is standard).

---

## Component 5 — UX Improvements

### UX-01 · Admin menu capability logic is inverted

| Field | Value |
|-------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Modules/Admin/AdminMenu.php` · Lines 13–20 |
| **Root Cause** | The fallback chain lowers the required capability instead of raising it, granting viewer-role users full admin page access. |

**Required fix:**

Use a single minimum capability for menu visibility. Pass all user capabilities to the frontend SPA for per-tab gating:

```php
public function registerMenu(): void {
    add_menu_page(
        'Holvek SEO',
        'Holvek',
        'edit_seo_meta', // Minimum capability to see the menu
        'holvek',
        array( $this, 'renderAdminPage' ),
        'dashicons-chart-area',
        85
    );
}
```

In `enqueueAssets()`, add capability flags to `wp_localize_script()`:

```php
$adminConfig['capabilities'] = array(
    'manage_seo'       => current_user_can( 'manage_seo' ),
    'view_seo_reports' => current_user_can( 'view_seo_reports' ),
    'manage_redirects' => current_user_can( 'manage_redirects' ),
    'edit_seo_meta'    => current_user_can( 'edit_seo_meta' ),
    'manage_options'   => current_user_can( 'manage_options' ),
);
```

**Acceptance criteria:**

- [ ] A user with only `edit_seo_meta` can see the menu item but does NOT see the Settings tab in the SPA (enforced by the frontend React app reading `capabilities.manage_seo`).
- [ ] A user with `manage_seo` sees all tabs.
- [ ] A user with no SEO capabilities does NOT see the menu item at all.
- [ ] The `holvekAdminConfig` JS object includes a `capabilities` map.

---

### UX-02 · SEO score column relies on color alone

| Field | Value |
|-------|-------|
| **Severity** | LOW |
| **File** | `src/Modules/Admin/SeoScoreColumn.php` · Lines 55–65 |
| **Root Cause** | WCAG 1.4.1 violation — color dot is the only visual indicator. `$label` is computed but only used in the `title` tooltip. |

**Required fix:**

Show the text label visually alongside the score number:

```php
printf(
    '<span title="%s — %d/100" style="display:inline-flex;align-items:center;gap:4px;">
        <span style="display:inline-block;width:10px;height:10px;border-radius:50%%;background:%s;" aria-hidden="true"></span>
        <span style="font-size:12px;font-weight:500;color:%s;">%d</span>
        <span class="screen-reader-text">%s</span>
    </span>',
    esc_attr( $label ),
    $score,
    esc_attr( $color ),
    esc_attr( $color ),
    $score,
    esc_html( $label )
);
```

**Acceptance criteria:**

- [ ] Screen readers announce the text label ("Good", "OK", or "Poor") via `.screen-reader-text`.
- [ ] The color dot has `aria-hidden="true"`.
- [ ] Sighted users still see the numeric score with the color indicator.

---

### UX-03 · Frontend inspector lacks keyboard accessibility

| Field | Value |
|-------|-------|
| **Severity** | LOW |
| **File** | `src/Modules/Admin/FrontendSeoInspector.php` · Lines 140–199 |

**Required fix:**

Add keyboard support to the JS:

```js
toggle.setAttribute('aria-expanded', 'false');
panel.setAttribute('role', 'dialog');
panel.setAttribute('aria-label', 'SEO Inspector');

toggle.addEventListener('click', () => {
    const isOpen = panel.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(isOpen));
    // ... existing fetch logic ...
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && panel.classList.contains('open')) {
        panel.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
    }
});
```

**Acceptance criteria:**

- [ ] Pressing Escape while the panel is open closes it and returns focus to the toggle button.
- [ ] The toggle button has `aria-expanded="true"` when open and `aria-expanded="false"` when closed.
- [ ] The panel has `role="dialog"` and an `aria-label`.

---

## Component 6 — Database Versioning

### DB-01 · No database schema version tracking

| Field | Value |
|-------|-------|
| **Severity** | HIGH |
| **File** | `src/Core/Database/Installer.php` |
| **Root Cause** | Tables are created via `dbDelta()` but no version is recorded. On upgrades, there is no mechanism to determine if a schema migration is needed. |

**Required fix:**

Add version tracking after successful installation:

```php
public function install(): void {
    // ... existing dbDelta calls ...

    update_option( 'holvek_db_version', HOLVEK_VERSION, false );
}
```

In `Plugin::boot()`, add an upgrade check:

```php
$dbVersion = get_option( 'holvek_db_version', '0' );
if ( version_compare( $dbVersion, HOLVEK_VERSION, '<' ) ) {
    $installer = new \Holvek\Core\Database\Installer();
    $installer->install();
}
```

**Acceptance criteria:**

- [ ] After activation, `get_option('holvek_db_version')` returns the current plugin version.
- [ ] After updating the plugin to a newer version, the installer runs automatically on the next request.
- [ ] The option has `autoload = false` (not needed on frontend requests).
- [ ] Existing tables are not dropped or recreated — `dbDelta()` is non-destructive by design.

---

## Global Verification Checklist

Run after ALL fixes are applied:

- [ ] `composer run phpcs` — zero errors
- [ ] `composer run phpunit` — all tests pass
- [ ] `npm run build` — frontend build succeeds with no errors
- [ ] Activate plugin on a fresh WordPress 6.x install — no PHP warnings or errors
- [ ] Deactivate and reactivate — no data loss, capabilities intact
- [ ] Uninstall with `delete_data_on_uninstall = yes` — all custom tables, options, and post meta removed
- [ ] Uninstall with `delete_data_on_uninstall = no` — only cron hooks cleared, data preserved
- [ ] Load the homepage 10 times — Query Monitor shows no `UPDATE wp_options ... wp_user_roles` queries
- [ ] Load the admin dashboard — no PHP `Notice` or `Warning` messages
- [ ] Run `wp i18n make-pot . languages/holvek.pot` — all new strings present
- [ ] Test with WordPress 6.0 minimum version — no fatal errors from unsupported PHP/WP APIs
