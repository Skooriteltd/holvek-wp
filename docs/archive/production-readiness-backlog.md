# Production Readiness Backlog

This document tracks the consolidated production readiness backlog for the Holvek WordPress plugin. 

## P0: Release Blockers
*All P0 release blockers have been successfully implemented and verified.*
- [x] **PR-001 to PR-008**: Core functionality verification (REST routes, packaging, activation/uninstall, privacy tests, image optimization). 
- [x] **CR-001**: Establish a real WordPress release test matrix (E2E workflows created via `wp-env`).

## P1: Must-Haves for WordPress.org Submission
*All critical P1 blockers have been resolved.*
- [x] **CR-002 / PR-015**: Define and enforce WPCS baseline. (`phpcbf` fixed ~66,000 style issues. A strict `phpcs.xml.dist` was added. Plugin Check and WPCS CI workflows are active).
- [x] **PR-012 / CR-003**: Outbound request safety audits. All `wp_remote_*` calls refactored to `wp_safe_remote_*` to prevent SSRF. Strict timeouts and size bounds added to API/scraper calls.
- [x] **PR-009**: Page builder wrapper code removed.
- [x] **PR-010**: Cookie banner strictly framed as a notice, not a legal CMP.
- [x] **PR-011**: Plugin sitemap default coexistence with WP Core established.
- [x] **PR-013**: Broken-link scans batched and scheduled.

## P2: Open & Pending (Next Milestone / Polish)
These items are not strictly technical blockers for a v1.0 release, but represent ongoing hardening and operational readiness.

- [x] **PR-014**: Review all `add_menu_page` capability requirements across all modules to ensure lowest-privilege access.
- [x] **PR-016**: Third-party service disclosure list needs final documentation for the readme/settings page.
- [x] **PR-017**: Finalize legal wording with product/legal counsel regarding AI features.
- [x] **PR-018 to PR-021**: Miscellaneous edge-case hardening (queue visibility, cron recovery edge cases, scale testing).
- [x] **PR-022**: Admin bundle size optimization (webpack chunking for React bundles).
- [x] **PR-023**: Complete REST test coverage (unit test suite expansion for the 135 endpoints).
- [x] **PR-024**: Accessibility audit of custom Vue/React widgets in the admin area.
- [x] **PR-025**: Internationalization (i18n) complete string audit.
- [x] **PR-026**: Support operations runbook.
