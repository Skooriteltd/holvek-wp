# Holvek — Support & Operations Runbook

## 1. Architecture Overview
Holvek is built as a modular headless-first WordPress SEO plugin.
- **Frontend**: React-based SPA located in `src/assets/js/App.js` and injected into the WP Admin via `AdminMenu.php`.
- **Backend API**: 135+ secure REST API endpoints under the `holvek/v1` namespace.
- **Queueing**: Relies on Action Scheduler (if present) or falls back to WP-Cron for background tasks (e.g. Broken Link checks, IndexNow pinging).

## 2. Common Issues & Triage

### 2.1. React Dashboard Won't Load (Blank Screen)
- **Symptom**: Clicking "Holvek" in the WP admin shows a white screen.
- **Cause**: Usually a JavaScript error or capability mismatch.
- **Runbook**:
  1. Open Browser DevTools Console. Look for React crashes or 403 Forbidden errors.
  2. If 403 on `/holvek/v1/seo/global`, ensure the user has at least `manage_seo` capability.
  3. Check if another plugin is aggressive with caching WP REST API nonces.

### 2.2. IndexNow / Google Pings Not Firing
- **Symptom**: Posts are published but `api.indexnow.org` is not notified.
- **Cause**: WP-Cron is stalled, or the queue is bloated.
- **Runbook**:
  1. Navigate to `/wp-json/holvek/v1/system/queue` to check the queue status.
  2. If `driver` is `wp_cron` and `pending` is 250, the safety limit has engaged. We recommend installing the "Action Scheduler" plugin (or WooCommerce, which includes it) to handle high-scale background jobs.
  3. Ensure WP-Cron is firing (`define('DISABLE_WP_CRON', true)` and server cron is set up).

### 2.3. Google Search Console Shows "Unconfigured"
- **Symptom**: Dashboard shows an error about OAuth token missing.
- **Cause**: The OAuth 2.0 access token expired and the refresh token was revoked, or credentials were deleted.
- **Runbook**: 
  1. Go to Settings -> Integrations.
  2. Disconnect and Re-authenticate the Google account.

### 2.4. PageSpeed Insights Timeout
- **Symptom**: Bulk checking CWV (Core Web Vitals) fails with 502 Bad Gateway.
- **Cause**: Google PageSpeed API rate limiting.
- **Runbook**: Wait 60 seconds and retry. Holvek falls back to Holvek API infrastructure if a license is provided, but Google's underlying API still applies strict per-minute quotas.

## 3. Database & Cleanup
To completely reset Holvek data:
1. Delete all options prefixed with `holvek_`.
2. Clear the WordPress `cron` option of any `holvek_process_job` hooks.
3. Transients prefixed with `holvek_` are safe to delete at any time.
