# Holvek engineering backlog

Status date: 2026-10-09. Source commit: `101d608` on `main`. Tag `v1.0.0` points at that commit on `https://github.com/Skooriteltd/holvek-wp.git`.

This is the remaining work. Tasks 1–33, 35, and 36, the release cleanup, the schema boot fix, and the readme compatibility update stay in `docs/production-readiness.md`. Do not reopen them and do not rewrite their done paragraphs.

## Execution rules

1. Take one item. Finish its done-when checks before opening the next.
2. Reproduce the stated failure before editing. A local unit-suite pass does not close a GitHub failure.
3. Run `php -l`, `./vendor/bin/phpcs`, and `./vendor/bin/phpunit --testsuite Unit` as separate commands. The PHP binary is `/Applications/XAMPP/xamppfiles/bin/php`.
4. When an item is finished, add one newest-first paragraph at the top of the implementation status in `docs/production-readiness.md`. Leave every older paragraph unchanged.
5. `v1.0.0` stays on `101d608`. Ship these fixes as `1.0.1`. Moving `v1.0.0` again requires a separate explicit request.
6. `Tested up to` stays `7.1`. `Requires at least` stays `6.0`. `Requires PHP` stays `8.2`.
7. The UAT site is `http://localhost/holvek-uat` on WordPress 7.1.3. Search engines are discouraged there, so `/sitemap.xml` answers 404 until indexing is allowed or the Holvek sitemap option is turned on.

## Current evidence

Local PHP 8.2.4: PHPUnit 251 tests, 2454 assertions, exit 0. Whole-tree PHPCS exit 0. `package.json` `test:integration` has not been run. The WordPress test library is not installed.

Plugin Check was run on the installed 1.0.0 tree with `vendor` excluded, before `Tested up to` became 7.1. It reported 339 findings and 13 errors. The `outdated_tested_upto_header` error is already fixed on `101d608` and must be confirmed again, not fixed again.

GitHub Actions on `101d608`: Quality failed at “Run unit tests”. Coding Standards failed at “Run PHPCS” and “Run Plugin Check”. WordPress Plugin Check failed at “Run Plugin Check”. WordPress E2E Lifecycle failed at “Setup wp-env”; the other matrix legs were cancelled. The release-package job was still running when last checked. Job logs are not readable without repository admin rights.

## Wave 1 — Plugin Check errors

### EB-01. Keep development files out of the release zip

`package-zip.sh` copies the git archive and then excludes a fixed list. The zip built from `v1.0.0` still contains `.svnignore`, `scripts/release-gate.sh`, and `scripts/run-wordpress-integration-tests.sh`. Plugin Check reports those as `hidden_files` and `application_detected`. One further `hidden_files` warning was reported; identify that path from the zip listing.

Outcome: the zip contains the runtime plugin only. The scripts remain in the repository.

Touch: `package-zip.sh` only.

Leave unchanged: plugin behavior, readme version fields, and the `v1.0.0` tag.

Done when: `unzip -l dist/holvek-1.0.1.zip` has no dotfiles, no `scripts/`, and no shell scripts. `holvek.php`, `build/index.js`, and `vendor/autoload.php` are present. PHP syntax check of every PHP file in the zip exits 0.

Verify: `./package-zip.sh` after the `1.0.1` tag exists. Until that tag exists, verify with a dry listing of the staged tree from the same exclude rules.

### EB-02. Replace the inspector heredoc

`src/Modules/Admin/FrontendSeoInspector.php` builds the front-end inspector with a heredoc at line 231. Plugin Check reports `PluginCheck.CodeAnalysis.Heredoc.NotAllowed`.

Outcome: the same inspector script is assembled without `<<<`. The shadow host, nonce, post id, and translated labels stay the same. Output remains escaped for a JavaScript context through `wp_json_encode`.

Touch: `FrontendSeoInspector.php` and its existing test, if one covers the markup.

Leave unchanged: inspector layout, copy, and who can see the overlay.

Done when: Plugin Check no longer reports that heredoc. A logged-in editor on a public post still receives the inspector host. PHPCS on the file exits 0. The unit suite exits 0.

### EB-03. Remove `suppress_filters` from the orphan scan

`src/Modules/TechnicalSeo/OrphanedContentFinder.php` line 177 sets `suppress_filters` to true on the published-post query. Plugin Check reports `WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters`.

Outcome: the scan still pages published public post IDs by `ID` ascending, with `posts_per_page` at `POST_BATCH`, `no_found_rows`, and `ignore_sticky_posts`. It does not set `suppress_filters`.

Touch: `OrphanedContentFinder.php` and the test that covers one scan page.

Leave unchanged: the batch size, the inbound-count storage, and the queue handoff.

Done when: the query argument is gone, the page test still asserts the batch boundary, PHPCS on the file exits 0, and the unit suite exits 0.

### EB-04. Satisfy the `array_is_list` check without raising the WordPress requirement

Plugin Check reports `array_is_list()` at `src/Modules/Schema/JsonLd.php` lines 145, 162, 200, 224, and 267, and at `src/Modules/Indexing/GoogleIndexingApi.php` lines 114 and 147. It treats that function as a WordPress 6.5 polyfill. This plugin requires PHP 8.2, where `array_is_list()` exists, and WordPress 6.0.

Outcome: those calls use a project helper with the same list-versus-map result. `Requires at least` stays 6.0.

Touch: one helper and the two callers. Add a unit test for a list, a map, and an empty array.

Leave unchanged: schema acceptance rules and the Indexing API request body.

Done when: Plugin Check no longer reports `wp_function_not_compatible_with_requires_wp` for these files. The helper test and the unit suite exit 0.

### EB-05. Re-run Plugin Check and record the error list

Depends on EB-01 through EB-04.

Outcome: a fresh Plugin Check run against the packaged plugin, with `vendor` excluded, reports zero errors. The `Tested up to: 6.5` finding must not reappear.

Touch: none, unless the run shows a new error. A new error becomes the next item. It is not fixed inside this item.

Done when: the command exits and the error count is written into the new production-readiness paragraph, with the Plugin Check version and the zip name. Warnings stay open for Wave 2 and Wave 3.

## Wave 2 — Security and naming warnings

Each item fixes one sniff family. Record the file and line of every remaining hit in the done paragraph.

### EB-06. Sanitize request values before use

Five `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` hits, including `$_SERVER['REQUEST_URI']` in `src/Plugin.php`. One `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` hit in `src/Modules/Compliance/CcpaPrivacyTools.php` for `$_SERVER['HTTP_SEC_GPC']`.

Outcome: each value is unslashed and sanitized for its use. A URL path stays a path. The GPC signal still accepts only the expected token.

Done when: those two codes are absent from Plugin Check. The existing request and GPC tests pass.

### EB-07. Escape dynamic values passed into database queries

Four `PluginCheck.Security.DirectDB.UnescapedDBParameter` warnings.

Outcome: every reported value is passed through `$wpdb->prepare`, or the identifier is constrained to a known table or column name before interpolation. `{$wpdb->prefix}` plus a literal suffix stays the pattern for this plugin’s own tables.

Done when: that code is absent. No query behavior test changes its expected SQL shape except for added placeholders.

### EB-08. Prefix plugin globals

Five `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` hits, including `$deleteData` in `uninstall.php`. Two `WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound` hits.

Outcome: new global names start with `holvek_`. Dynamic hook names are built from a fixed prefix plus a validated suffix. The uninstall flag still means the same thing.

Done when: both codes are absent. Uninstall tests still cover the delete flag on and off.

### EB-09. Replace discouraged runtime calls

Two `Squiz.PHP.DiscouragedFunctions.Discouraged` hits and one `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` hit.

Outcome: `ini_set` is not used to change runtime limits on a front-end request. `load_plugin_textdomain` follows the current WordPress loading pattern for a plugin that ships `languages/holvek.pot`. Redirect validation still applies its backtrack and recursion limits only around the save-time `preg_match`, then restores the previous values.

Done when: both codes are absent. `RedirectManagerTest` still rejects a pattern that does not compile.

### EB-10. Make the direct AI warning match the documented opt-in

Four `PluginCheck.CodeAnalysis.AIProvider.DirectIntegration` warnings. The writing assistant is specified to call OpenAI, Anthropic, or Ollama with a key saved on the site. A remote Ollama host receives the submitted text. Nothing is sent until an administrator saves a provider.

Outcome: the warning is either cleared by the check’s supported disclosure pattern, or the done paragraph names the four sites and quotes the readme sentence that already discloses them. The providers stay.

Leave unchanged: provider endpoints, model names, and the system prompts.

## Wave 3 — Query warnings

Do not add a cache around every query. Do not mark a file `phpcs:ignore` as a batch. For each hit, keep the query when WordPress has no suitable API and the predicate is bounded and prepared, or change that one query when it is unbounded or unprepared.

### EB-11. Direct queries and their cache decision

One hundred forty-two `WordPress.DB.DirectDatabaseQuery.DirectQuery` warnings and one hundred twenty-nine `WordPress.DB.DirectDatabaseQuery.NoCaching` warnings, plus one `WordPress.DB.DirectDatabaseQuery.SchemaChange` warning in `uninstall.php`.

Outcome: schema changes stay in `Installer` and `uninstall.php`. Each remaining read or write is prepared, selects named columns, and has a stated reason when it does not use the object cache. User-specific rows are not stored in a shared cache key.

Done when: the production-readiness paragraph gives the new counts. A count of zero is not required for a justified query. An unprepared query is required to be gone.

### EB-12. Slow query shapes

Twenty `slow_db_query_meta_query` warnings, seven `PostNotIn_post__not_in` warnings, one `slow_db_query_tax_query`, and one `slow_db_query_meta_key`.

Outcome: each `post__not_in` list is bounded or replaced with a positive ID query. Meta and taxonomy queries stay only where the feature needs them, with a bounded `posts_per_page`.

Done when: any remaining hit is listed with its feature and its bound. Unbounded exclusions are gone.

## Wave 4 — GitHub checks

Start each item by reading the job log. The public annotations on `101d608` contain only the failing step name and the exit code.

### EB-13. Quality unit-test failure

`.github/workflows/quality.yml` runs `./vendor/bin/phpunit --testdox` on PHP 8.2. That step exited 1. The same command exits 0 locally with 251 tests and 2454 assertions.

Outcome: the failing test name and assertion are known from the log or from a reproduction on PHP 8.2 in a clean environment. The fix matches that failure.

Stop when: the log is unavailable and the failure does not reproduce. Record that stop. Do not edit tests speculatively.

Done when: the Quality workflow on the fix commit is green, or the stop is recorded.

### EB-14. PHPCS failure on Actions

`.github/workflows/coding-standards.yml` runs `vendor/bin/phpcs --report=checkstyle | cs2pr`. The step exited 2. Local `./vendor/bin/phpcs` exits 0. Actions uses `pipefail`, so either PHPCS or `cs2pr` can fail the step.

Outcome: the log identifies the file, sniff, and exit code. The fix changes that code or the workflow command. A warning is not hidden by excluding the file.

Done when: the PHPCS job on the fix commit is green, or the stop is recorded because the log and a local checkstyle run are both clean.

### EB-15. End-to-end environment startup

`.github/workflows/wordpress-e2e.yml` failed in “Setup wp-env” for PHP 8.2 and PHP 8.3. Later steps, including activation and `tests/e2e-privacy.php`, did not run. The workflow writes `.wp-env.json` with core `WordPress/WordPress#latest` and the working tree as the plugin.

Outcome: `wp-env start` completes, or the log gives a runner defect that is fixed in the workflow. The plugin folder activated by the later steps is the repository directory plus `holvek.php`.

Leave unchanged: product code, unless the log shows a fatal during activation after wp-env is up. That fatal becomes a new item.

Done when: one single-site PHP 8.2 leg activates Holvek, sees the `holvek_` tables, deactivates, and uninstalls. The multisite leg does the same with network activation.

### EB-16. Release-package install

`.github/workflows/release-package.yml` runs on tags. It builds with `package-zip.sh`, starts wp-env on `WordPress/WordPress#master`, activates `holvek`, and checks that `SHOW TABLES` contains `holvek`. The previous tag failed in “Test ZIP installation in temporary WordPress”. The run for `101d608` was still in progress at the last check.

Outcome: read that run first. A zip-install failure caused by EB-01 is closed by EB-01 and a new tag build. A `wp-env` startup failure is handled with EB-15’s fix.

Done when: the workflow for the `1.0.1` tag uploads `holvek-1.0.1.zip` and the table check passes.

## Wave 5 — Directory submission

### EB-17. Replace the directory screenshots with the real UI

`assets/wporg/` and `assets/screenshots/` still show the previous product name. `readme.txt` has no `== Screenshots ==` section. `assets/wporg/ASSETS-README.md` says those mockups must not be uploaded.

Outcome: new captures come from the UAT site or a clean site running the `1.0.1` zip. Required directory sizes stay `icon-128x128`, `icon-256x256`, `banner-772x250`, and `banner-1544x500`. Screenshot filenames stay lowercase. The readme screenshot list matches the files.

Leave unchanged: generated images that are not captured from the product. Icons and banners stay unless their artwork shows the previous name.

Done when: every screenshot file in `assets/wporg/` shows Holvek, and the readme section names those files.

### EB-18. Run the integration script

Depends on EB-15. `scripts/run-wordpress-integration-tests.sh` starts wp-env from `tests/wp-env.json`, activates `holvek-wp`, runs `tests/e2e-privacy.php` and `tests/e2e-image-optimization.php`, then uninstalls. Confirm the activated path is the real plugin file before treating a failure as a product defect.

Outcome: the script exits 0 against the release commit, or its first real failure is a new backlog item.

Done when: the command result and the WordPress and PHP versions are in the done paragraph. This script does not replace PHPUnit.

### EB-19. Cut 1.0.1

Depends on Wave 1 and Wave 4. Wave 2, Wave 3, and EB-17 may land in the same release when they are already done. They do not block the tag when their remaining hits are warnings recorded in EB-05.

Outcome: `readme.txt` stable tag and changelog are `1.0.1`. `HOLVEK_VERSION` and the plugin header match. `package-zip.sh` builds `dist/holvek-1.0.1.zip` from an annotated `v1.0.1` tag. `main` and `v1.0.1` are pushed. `v1.0.0` is left on `101d608`.

Done when: `git ls-remote` shows `v1.0.1` peeled to that commit, and the zip’s `readme.txt` says `Stable tag: 1.0.1` and `Tested up to: 7.1`.

### EB-20. WordPress.org submission checklist

Depends on EB-19 and EB-17.

Outcome: a person submits the `1.0.1` zip. The checklist below is ticked from commands and from the zip, not from memory.

- Plugin Check on the zip reports zero errors.
- PHPCS and PHPUnit exit 0 on the tagged commit.
- Quality, Coding Standards, Plugin Check, E2E, and Release package are green on that tag.
- The zip has no tests, docs, node modules, development scripts, or dotfiles.
- Readme external services match the code. No Discovery Scan, ranking, or compliance claim.
- Screenshots show Holvek.
- Uninstall with the delete flag removes Holvek tables and `holvek_` options and leaves other content.

Done when: the submission URL or the reviewer’s requested changes are recorded. A review request for a code change becomes a new item. It is not fixed inside this item.
