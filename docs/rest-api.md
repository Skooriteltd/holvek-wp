# REST API Reference

Holvek exposes over 130 secure REST API endpoints under the `holvek/v1` namespace. This headless-first design allows full manipulation of SEO data without ever touching the WordPress PHP frontend.

## Authentication
All write operations and sensitive read operations require standard WordPress REST API authentication (Cookie/Nonce for internal React, or Application Passwords for external headless clients).
Most endpoints require the `manage_seo` or `view_seo_reports` capability.

## Core Endpoints

### 1. Global SEO Settings
`GET /wp-json/holvek/v1/seo/global`
Returns the global fallback settings (default meta formats, social graphs, schema configurations).

### 2. Post-Level SEO Data
`GET /wp-json/holvek/v1/seo/(?P<id>\d+)`
`PUT /wp-json/holvek/v1/seo/(?P<id>\d+)`
Retrieve or update the exact SEO overrides for a specific post, page, or custom post type.

### 3. Machine-Readable Schema Map
`GET /wp-json/holvek/v1/schemamap`
A publicly accessible, AI-friendly JSON endpoint that outlines the structured data graph of the entire site. Designed specifically to be ingested by LLMs.

### 4. Background Queue Health
`GET /wp-json/holvek/v1/system/queue`
Exposes the status of the Action Scheduler and WP-Cron fallback queues. Useful for monitoring massive batch operations like broken link checking or bulk schema updates.

### 5. Competitor Migration
`POST /wp-json/holvek/v1/migration/run`
Executes the one-click migration logic to safely convert Yoast, Rank Math, or All in One SEO data into Holvek formatting.
