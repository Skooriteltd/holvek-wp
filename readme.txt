=== Holvek ===
Contributors: skoorite
Tags: seo, schema, sitemap, indexing, search console
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Technical SEO, structured data, sitemaps, redirects, and indexing tools for WordPress.

== Description ==

Holvek writes SEO titles, meta descriptions, schema, the sitemap, and robots.txt on the WordPress site where it is installed. A Holvek account is optional.

Use it to keep technical SEO on this site: titles and meta descriptions, canonical URLs, robots meta, XML Sitemaps, the Redirects Manager, and structured data.

### Editor

The post editor includes checks for:

* SEO titles and meta descriptions
* Readability
* A search-result preview
* Social metadata for Facebook, X, LinkedIn, and Pinterest
* Canonical URLs and robots meta
* FAQ schema
* Internal link suggestions
* A notice when cornerstone content has not been updated for 180 days
* PageSpeed for the post you are editing

The writing assistant uses an OpenAI, Anthropic, or Ollama key saved on this site. A remote Ollama host receives the text you submit.

### Schema Markup

Schema Markup can describe a page as Article, NewsArticle, WebPage, BreadcrumbList, FAQ, HowTo, LocalBusiness, Recipe, Event, Product, VideoObject, Person, or Organization. You can also add one custom JSON-LD node.

When WooCommerce is active, product schema can include an aggregate rating if that product has reviews. Structured data does not guarantee a rich result.

### XML Sitemaps and the HTML Sitemap

* The sitemap index is `/sitemap.xml`.
* The news sitemap is `/sitemap-news.xml` and `/news-sitemap.xml`.
* The video sitemap is `/video-sitemap.xml`.
* `[holvek_sitemap]` prints an HTML Sitemap.

### Technical SEO

The Technical SEO screen includes the Redirects Manager. A redirect can be 301, 302, 307, or 410, including a regular expression, and you can import rules from CSV. The 404 log records missing URLs.

robots.txt starts from the file WordPress already generated. Holvek appends its lines unless an administrator confirms a full replacement.

The `.htaccess` editor stays off until an administrator turns it on. It does not run when WordPress file editing is disabled. Nginx does not read `.htaccess`.

### Technical SEO Audit

This audit runs on this site. It checks meta descriptions, image alt text, 404s, redirect chains, orphaned pages, noindex directives, the sitemap, robots.txt, the site icon, SSL, schema, duplicate titles, cornerstone staleness, and canonicals. It does not score AI mentions, Google presence, or competitors.

The site icon check is part of this audit. Holvek does not include a separate favicon tool.

### Link Health and the Broken Link Checker

Link Health lists internal links and orphaned pages. The Broken Link Checker scans published content in small batches.

### llms.txt

Holvek publishes an llms.txt file on this site. It does not submit the site to ChatGPT, Perplexity, or Claude, and it does not check whether they mention it.

### Image SEO

Image SEO lists images that have no alternative text, can rename new uploads to lowercase hyphenated file names, and can add image schema.

### Bulk Meta Editor

The Bulk Meta Editor edits SEO titles and meta descriptions for many posts.

### cookie notice

The cookie notice records a visitor choice. It does not block third-party scripts and is not a consent-management platform. Holvek can also add an AI content disclosure.

### Roles & Capabilities

Roles & Capabilities control who can manage redirects, edit SEO meta, and open SEO screens. An audit log records changes to titles, descriptions, and settings.

### Google Analytics 4, Google Search Console, and IndexNow

Google Analytics 4 and Google Search Console load after you connect them. Search Console can list queries matched by more than one page.

IndexNow sends published URLs only after an administrator saves a key and turns notifications on. IndexNow does not guarantee indexing. The Google Indexing API is optional, applies to eligible JobPosting or livestream pages, and does not guarantee indexing.

The Keyword Rank Tracker uses a SerpApi key saved on this site.

A Bing Webmaster tools verification code can be saved in settings. Holvek does not include a Bing traffic screen.

Social posting can send to Facebook, X, LinkedIn, and Pinterest. Instagram and YouTube can be stored as profile URLs. Holvek does not post to Instagram or TikTok.

### Setup, import, multisite, and headless

The setup wizard can store a site type and profile URLs. Titles and the sitemap already work before you finish it.

Holvek can import titles, descriptions, and settings from Yoast SEO, Rank Math, and All in One SEO. The import shows how many fields it will copy and does not write until you confirm.

On a multisite network, a network screen is available. It is not shown on a single site.

The REST API can read public SEO fields for a published post. Writing SEO data requires a user who can edit that post.

### Connecting Holvek

A Holvek account is optional. The settings screen cannot change the Holvek API host.

An administrator can paste a site token or an authorization code. Holvek sends that request only to `https://api.holvek.com`. The base address is `https://api.holvek.com/v1` unless code overrides it with the `holvek_backend_api_url` filter.

A site token is JSON posted to `/auth/site-token` with `grant_type` set to `site_token`, the pasted `site_token`, and `site_url` set to this site's home address. An authorization code is JSON posted to `/auth/token` with `grant_type` set to `authorization_code`, `code`, and the same `site_url`. Holvek does not store the pasted token or code. It stores the returned access token only after `GET /auth/verify` says `active` is true.

Each of these requests waits 10 seconds. Holvek tries once more when the connection fails, or when the response is HTTP 408, 429, or 500 or higher. A rejected credential is not tried again, and nothing is saved.

Checking the saved credential sends `GET /auth/verify`. Rotating it sends `POST /auth/rotate` with the saved credential and `site_url`. The replacement is stored only after it verifies. If that fails, the saved credential stays.

Disconnect sends `POST /auth/revoke`, then deletes the credential stored on this site. If Holvek cannot be reached, the credential is still removed here. Titles, the sitemap, redirects, and 404 logs are kept. The remote token can remain valid until Holvek is reachable.

A page view does not call Holvek. A tier comes from the verification response and expires. The settings screen cannot set it.

### What this release leaves out

It does not score AI mentions, Google presence, or competitors. It does not include a client reporting portal or a multi-brand workspace.

= External services =

For every service below, nothing is sent until an administrator turns the feature on. A normal page view does not call these services.

The writing assistant sends the current post title, meta description, or an excerpt to OpenAI at https://api.openai.com, or to Anthropic at https://api.anthropic.com, when an editor runs it. Ollama defaults to http://localhost:11434 on this server. A remote http or https host receives the prompt, which can include the post title and content, only after an administrator confirms that host. A settings import cannot set a remote Ollama host.

The Keyword Rank Tracker sends focus keywords to SerpApi at https://serpapi.com when a key is saved and a check runs. PageSpeed sends the URL being checked to Google. Google Search Console uses read-only access for impressions, clicks, and click-through rate after an administrator connects it. Holvek does not write Search Console data to Google. Google Analytics 4 reports are requested after an administrator saves the property credentials and opens the report.

IndexNow sends published URLs, after an administrator saves a key and turns notifications on, only to https://api.indexnow.org, https://www.bing.com, https://yandex.com, and https://search.seznam.cz. IndexNow does not guarantee indexing. The Google Indexing API sends a published URL only when an administrator turns it on, and only for an eligible JobPosting or livestream page. It does not guarantee indexing.

Social posting can send a post URL to Facebook, X, LinkedIn, or Pinterest after an administrator connects that account. Holvek does not post to Instagram or TikTok.

A Bing Webmaster tools verification code can be saved in settings. This release does not include a Bing traffic screen. The Bing Webmaster API is used only when a Bing API key is already stored.

Connecting Holvek sends the requests described above only to https://api.holvek.com. The settings screen cannot change that host. A Holvek account is optional.

Service privacy policies: https://openai.com/privacy , https://www.anthropic.com/privacy , https://serpapi.com/privacy , https://policies.google.com/privacy , https://www.indexnow.org/ , and https://holvek.com/privacy .

== Installation ==

1. Upload the `holvek` folder to `/wp-content/plugins/`.
2. Activate **Holvek** from the **Plugins** screen in WordPress.
3. Open **Holvek** from the WordPress admin menu.
4. Titles, the sitemap, and robots.txt already work. Use the setup wizard if you want to choose a site type.
5. Connect Google Search Console when you want search data in WordPress. IndexNow, PageSpeed, and the Keyword Rank Tracker stay off until you save their credentials.

== Frequently Asked Questions ==

= Does Holvek need an API key? =

No. Core SEO, structured data, sitemaps, redirects, robots.txt, and other local features work without external API credentials.

External connections are only required for features that depend on third-party services.

= Can the settings screen change the Holvek API host? =

No. The settings screen cannot change the Holvek API host. Connection requests go to https://api.holvek.com and wait 10 seconds, with one retry when Holvek does not answer. A Holvek account is optional. Disconnecting removes the credential stored on this site even when Holvek cannot be reached, and SEO data is kept. The remote token can remain valid until Holvek is reachable.

= Can I use Holvek with WooCommerce? =

Yes. Holvek supports WooCommerce product structured data, including an aggregate rating when that product has reviews, and related SEO controls when WooCommerce is active.

= Can I migrate from Yoast SEO, Rank Math, or All in One SEO? =

Yes. Supported SEO data can be imported from Yoast SEO, Rank Math, and All in One SEO. The import shows how many fields it will copy and does not write until you confirm.

= Will Holvek guarantee higher Google rankings? =

No SEO plugin can guarantee a particular ranking position or search-result appearance.

Holvek maintains technical signals, structured data, sitemaps, redirects, and indexing controls on your site.

= Does Holvek score AI mentions or compare competitors? =

No. The Technical SEO Audit runs on this site. It does not score AI mentions, Google presence, or competitors.

= Can I use Holvek on a headless WordPress site? =

Yes. The REST API can read public SEO fields for a published post. Writing SEO data requires a user who can edit that post.

= What happens when I deactivate the plugin? =

Deactivating Holvek stops its plugin functionality. Existing plugin data is preserved unless you explicitly choose to remove it during uninstall.

= What happens to data saved by the previous plugin name? =

On upgrade, Holvek renames the previous tables, options, and meta to the `holvek_` names. The HTML sitemap shortcode is `[holvek_sitemap]`. Secrets saved with the previous ciphertext prefix can still be read. New secrets are saved as `holvek_enc::`.

= Does Holvek work with existing themes and page builders? =

Yes. Holvek is designed to work alongside existing WordPress themes, editors, and page-building tools without requiring a specific frontend.

== Support ==

For documentation and technical guidance, see the plugin documentation included with Holvek.

For WordPress.org support, use the Holvek support forum:

https://wordpress.org/support/plugin/holvek/

When reporting a problem, include your WordPress version, PHP version, Holvek version, and the steps required to reproduce the issue.

== Changelog ==

= 1.0.0 =

* Initial release. Requires PHP 8.2.
* Upgrade renames tables, options, and meta saved under the previous plugin name. The public constants, functions, sitemap shortcode, and opt-out action from that name are not included. Secrets saved with the previous ciphertext prefix can still be read. New secrets are saved as `holvek_enc::`.
* Technical SEO controls and metadata management.
* XML, News, and Video sitemap support.
* JSON-LD structured data with WooCommerce support.
* Redirect management and 404 monitoring.
* Image SEO and internal linking tools.
* Google Search Console integration.
* A Bing Webmaster tools verification code, and IndexNow notifications. IndexNow does not guarantee indexing.
* REST API support for public SEO fields on a published post.
* Migration tools for supported SEO plugins. The import shows how many fields it will copy and does not write until you confirm.
* The Technical SEO Audit checks this site. It does not score AI mentions, Google presence, or competitors.
* An administrator can connect, check, rotate, or disconnect a Holvek site credential. Requests go to https://api.holvek.com, wait 10 seconds, and retry once. The settings screen cannot change the Holvek API host. A rejected credential is not saved. Disconnect removes the local credential and keeps SEO data.
