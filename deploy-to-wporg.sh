#!/usr/bin/env bash
# =============================================================================
# Holvek — WordPress.org SVN Deploy Script
# =============================================================================
# Usage:
#   chmod +x deploy-to-wporg.sh
#   ./deploy-to-wporg.sh
#
# Requirements:
#   - svn installed
#   - composer installed
#   - Your WP.org username and plugin slug set below
# =============================================================================

set -e

PLUGIN_SLUG="holvek"
WP_ORG_USER="${WP_ORG_USER:-holvekteam}"
VERSION=$(grep "Stable tag:" readme.txt | sed 's/Stable tag: //' | tr -d '[:space:]')
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
BUILD_DIR="$(pwd)/.svn-build"
PLUGIN_DIR="$(pwd)"

echo "==> Deploying Holvek v${VERSION} to WordPress.org"
echo "    SVN URL : ${SVN_URL}"
echo "    WP User : ${WP_ORG_USER}"
echo ""

# ── 1. Build frontend assets ──────────────────────────────────────────────────
echo "==> Building frontend assets..."
npm ci --silent
npm run build

# ── 2. Install production Composer dependencies ───────────────────────────────
echo "==> Installing production composer dependencies..."
composer install --no-dev --optimize-autoloader --quiet

# ── 3. Checkout SVN trunk & assets ────────────────────────────────────────────
echo "==> Checking out SVN..."
rm -rf "${BUILD_DIR}"
svn checkout "${SVN_URL}" "${BUILD_DIR}" --depth immediates
svn update "${BUILD_DIR}/trunk" --set-depth infinity
svn update "${BUILD_DIR}/assets" --set-depth infinity

# ── 4. Sync plugin files to trunk (exclude dev files) ─────────────────────────
echo "==> Syncing files to SVN trunk..."
rsync -rc --delete \
    --exclude=".git" \
    --exclude=".github" \
    --exclude=".kilo" \
    --exclude=".*" \
    --exclude=".svn" \
    --exclude=".svn-build" \
    --exclude=".svnignore" \
    --exclude=".gitignore" \
    --exclude=".phpcs.xml" \
    --exclude=".editorconfig" \
    --exclude=".DS_Store" \
    --exclude="node_modules" \
    --exclude="tests" \
    --exclude="phpunit.xml" \
    --exclude="phpunit.xml.dist" \
    --exclude="composer.phar" \
    --exclude="composer.lock" \
    --exclude="package.json" \
    --exclude="package-lock.json" \
    --exclude="tailwind.config.js" \
    --exclude="postcss.config.js" \
    --exclude="backlog.md" \
    --exclude="deferred-backlog.md" \
    --exclude="production-readiness-backlog.md" \
    --exclude="about.md" \
    --exclude="features.md" \
    --exclude="tasklist.md" \
    --exclude="specification.md" \
    --exclude="deploy-to-wporg.sh" \
    --exclude="package-zip.sh" \
    --exclude="dist" \
    --exclude="docs" \
    --exclude="*.md" \
    --exclude="assets/screenshots" \
    --exclude="assets/wporg" \
    --exclude="assets/raw-design-sources" \
    "${PLUGIN_DIR}/" "${BUILD_DIR}/trunk/"

# ── 5. Copy assets (banner, icons, screenshots) ───────────────────────────────
if [ -d "${PLUGIN_DIR}/assets/wporg" ]; then
    echo "==> Syncing WP.org assets (banners, icons, screenshots)..."
    rsync -rc \
        --exclude="sources" \
        --exclude="ASSETS-README.md" \
        --exclude=".DS_Store" \
        "${PLUGIN_DIR}/assets/wporg/" "${BUILD_DIR}/assets/"
fi

# ── 6. SVN add new files, remove deleted files across trunk and assets ────────
echo "==> Updating SVN file statuses..."
cd "${BUILD_DIR}"

# Add untracked files (macOS BSD and GNU compatible)
svn status trunk assets | grep "^\?" | awk '{print $2}' | while read -r file; do
    if [ -n "$file" ]; then
        svn add "$file"
    fi
done

# Remove deleted files (macOS BSD and GNU compatible)
svn status trunk assets | grep "^\!" | awk '{print $2}' | while read -r file; do
    if [ -n "$file" ]; then
        svn delete "$file"
    fi
done

# ── 7. Tag the release ────────────────────────────────────────────────────────
echo "==> Tagging release v${VERSION}..."
svn cp trunk "tags/${VERSION}"

# ── 8. Commit ─────────────────────────────────────────────────────────────────
echo "==> Committing to WordPress.org SVN..."
svn commit \
    --username "${WP_ORG_USER}" \
    -m "Release v${VERSION}"

# 8. Restore dev composer dependencies ─────────────────────────────────────
echo "==> Restoring dev composer dependencies..."
cd "${PLUGIN_DIR}"
composer install --quiet

echo ""
echo "✅ Holvek v${VERSION} deployed to WordPress.org"
echo "   View at: https://wordpress.org/plugins/${PLUGIN_SLUG}/"
