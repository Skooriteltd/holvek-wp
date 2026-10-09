# Holvek features

Holvek writes SEO titles, meta descriptions, schema, the sitemap, and robots.txt on this WordPress site. A Holvek account is optional.

Technical SEO, structured data, sitemaps, redirects, and indexing tools for WordPress.

The screens below are the feature set for this release. The same names are used in `readme.txt` and the admin UI.

## Editor

The post editor includes:

- SEO titles and meta descriptions
- Readability
- A search-result preview
- Social metadata for Facebook, X, LinkedIn, and Pinterest
- Canonical URLs and robots meta
- FAQ schema
- Internal link suggestions
- A notice when cornerstone content has not been updated for 180 days
- PageSpeed for the post you are editing

The writing assistant uses an OpenAI, Anthropic, or Ollama key saved on this site. A remote Ollama host receives the text you submit.

## Schema Markup

Schema Markup can describe a page as Article, NewsArticle, WebPage, BreadcrumbList, FAQ, HowTo, LocalBusiness, Recipe, Event, Product, VideoObject, Person, or Organization. You can also add one custom JSON-LD node.

When WooCommerce is active, product schema can include an aggregate rating if that product has reviews. Structured data does not guarantee a rich result.

## XML Sitemaps and the HTML Sitemap

- The sitemap index is `/sitemap.xml`.
- The news sitemap is `/sitemap-news.xml` and `/news-sitemap.xml`.
- The video sitemap is `/video-sitemap.xml`.
- `[holvek_sitemap]` prints an HTML Sitemap.

## Technical SEO

The Technical SEO screen includes the Redirects Manager. A redirect can be 301, 302, 307, or 410, including a regular expression, and you can import rules from CSV. The 404 log records missing URLs.

robots.txt starts from the file WordPress already generated. Holvek appends its lines unless an administrator confirms a full replacement.

The `.htaccess` editor stays off until an administrator turns it on. It does not run when WordPress file editing is disabled. Nginx does not read `.htaccess`.

## Technical SEO Audit

This audit runs on this site. It checks meta descriptions, image alt text, 404s, redirect chains, orphaned pages, noindex directives, the sitemap, robots.txt, the site icon, SSL, schema, duplicate titles, cornerstone staleness, and canonicals. It does not score AI mentions, Google presence, or competitors.

The site icon check is part of this audit. Holvek does not include a separate favicon tool.

## Link Health and the Broken Link Checker

Link Health lists internal links and orphaned pages. The Broken Link Checker scans published content in small batches.

## llms.txt

Holvek publishes an llms.txt file on this site. It does not submit the site to ChatGPT, Perplexity, or Claude, and it does not check whether they mention it.

## Image SEO

Image SEO lists images that have no alternative text, can rename new uploads to lowercase hyphenated file names, and can add image schema.

## Bulk Meta Editor

The Bulk Meta Editor edits SEO titles and meta descriptions for many posts.

## cookie notice

The cookie notice records a visitor choice. It does not block third-party scripts and is not a consent-management platform. Holvek can also add an AI content disclosure.

## Roles & Capabilities

Roles & Capabilities control who can manage redirects, edit SEO meta, and open SEO screens. An audit log records changes to titles, descriptions, and settings.

## Google Analytics 4, Google Search Console, and IndexNow

Google Analytics 4 and Google Search Console load after you connect them. Search Console can list queries matched by more than one page.

IndexNow sends published URLs only after an administrator saves a key and turns notifications on. IndexNow does not guarantee indexing. The Google Indexing API is optional, applies to eligible JobPosting or livestream pages, and does not guarantee indexing.

The Keyword Rank Tracker uses a SerpApi key saved on this site.

A Bing Webmaster tools verification code can be saved in settings. Holvek does not include a Bing traffic screen.

Social posting can send to Facebook, X, LinkedIn, and Pinterest. Instagram and YouTube can be stored as profile URLs. Holvek does not post to Instagram or TikTok.

## Setup, import, multisite, and headless

The setup wizard can store a site type and profile URLs. Titles and the sitemap already work before you finish it.

Holvek can import titles, descriptions, and settings from Yoast SEO, Rank Math, and All in One SEO. The import shows how many fields it will copy and does not write until you confirm.

On a multisite network, a network screen is available. It is not shown on a single site.

The REST API can read public SEO fields for a published post. Writing SEO data requires a user who can edit that post.

## Connecting Holvek

A Holvek account is optional. The settings screen cannot change the Holvek API host.

An administrator can paste a site token or an authorization code. Holvek sends that request only to `https://api.holvek.com`. The base address is `https://api.holvek.com/v1` unless code overrides it with the `holvek_backend_api_url` filter.

A site token is JSON posted to `/auth/site-token` with `grant_type` set to `site_token`, the pasted `site_token`, and `site_url` set to this site's home address. An authorization code is JSON posted to `/auth/token` with `grant_type` set to `authorization_code`, `code`, and the same `site_url`. Holvek does not store the pasted token or code. It stores the returned access token only after `GET /auth/verify` says `active` is true.

Each of these requests waits 10 seconds. Holvek tries once more when the connection fails, or when the response is HTTP 408, 429, or 500 or higher. A rejected credential is not tried again, and nothing is saved.

Checking the saved credential sends `GET /auth/verify`. Rotating it sends `POST /auth/rotate` with the saved credential and `site_url`. The replacement is stored only after it verifies. If that fails, the saved credential stays.

Disconnect sends `POST /auth/revoke`, then deletes the credential stored on this site. If Holvek cannot be reached, the credential is still removed here. Titles, the sitemap, redirects, and 404 logs are kept. The remote token can remain valid until Holvek is reachable.

A page view does not call Holvek. A tier comes from the verification response and expires. The settings screen cannot set it.

## What this release leaves out

It does not score AI mentions, Google presence, or competitors. It does not include a client reporting portal or a multi-brand workspace.
