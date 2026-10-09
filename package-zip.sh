#!/usr/bin/env bash
# Build a WordPress.org distribution from an immutable, clean release tag.
set -euo pipefail

readonly PLUGIN_SLUG='holvek'
readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly OUTPUT_DIR="${SCRIPT_DIR}/dist"
readonly BUILD_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${PLUGIN_SLUG}-release.XXXXXX")"

cleanup() {
    rm -rf "${BUILD_DIR}"
}
trap cleanup EXIT

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "ERROR: Required command not found: $1" >&2
        exit 1
    fi
}

for command in git tar rsync npm composer zip unzip shasum php; do
    require_command "${command}"
done

cd "${SCRIPT_DIR}"

if [[ -n "$(git status --porcelain)" ]]; then
    echo 'ERROR: Refusing to package a dirty worktree. Commit or stash every change first.' >&2
    exit 1
fi

RELEASE_TAG="$(git describe --exact-match --tags HEAD 2>/dev/null || true)"
if [[ -z "${RELEASE_TAG}" ]]; then
    echo 'ERROR: HEAD must be an exact annotated or lightweight release tag.' >&2
    exit 1
fi

VERSION="$(awk -F: 'tolower($1) == "stable tag" {gsub(/[[:space:]]/, "", $2); print $2; exit}' readme.txt)"
if [[ -z "${VERSION}" ]]; then
    echo 'ERROR: readme.txt must define Stable tag.' >&2
    exit 1
fi

TAG_VERSION="${RELEASE_TAG#v}"
if [[ "${TAG_VERSION}" != "${VERSION}" ]]; then
    echo "ERROR: Tag ${RELEASE_TAG} does not match readme Stable tag ${VERSION}." >&2
    exit 1
fi

if ! php -r 'exit(version_compare(PHP_VERSION, "8.2", ">=") ? 0 : 1);'; then
    echo 'ERROR: PHP 8.2 or newer is required.' >&2
    exit 1
fi

readonly SOURCE_DIR="${BUILD_DIR}/source"
readonly STAGE_DIR="${BUILD_DIR}/stage/${PLUGIN_SLUG}"
readonly ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
readonly ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"

mkdir -p "${SOURCE_DIR}" "${STAGE_DIR}" "${OUTPUT_DIR}"
git archive --format=tar "${RELEASE_TAG}" | tar -x -C "${SOURCE_DIR}"

(
    cd "${SOURCE_DIR}"
    npm ci --silent
    npm run build
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet
)

rsync -a --delete \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='dist' \
    --exclude='assets/screenshots' \
    --exclude='assets/wporg' \
    --exclude='assets/raw-design-sources' \
    --exclude='docs' \
    --exclude='*.md' \
    --exclude='composer.lock' \
    --exclude='package-lock.json' \
    --exclude='package.json' \
    --exclude='phpunit.xml*' \
    --exclude='phpcs.xml*' \
    --exclude='deploy-to-wporg.sh' \
    --exclude='package-zip.sh' \
    "${SOURCE_DIR}/" "${STAGE_DIR}/"

if [[ ! -f "${STAGE_DIR}/holvek.php" || ! -f "${STAGE_DIR}/vendor/autoload.php" || ! -f "${STAGE_DIR}/build/index.js" ]]; then
    echo 'ERROR: The staged plugin is missing a required runtime file.' >&2
    exit 1
fi

find "${STAGE_DIR}" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
(
    cd "${BUILD_DIR}/stage"
    zip -X -r -q "${ZIP_PATH}" "${PLUGIN_SLUG}" -x '*.DS_Store'
)

unzip -tq "${ZIP_PATH}" >/dev/null
(
    cd "${BUILD_DIR}/stage"
    find "${PLUGIN_SLUG}" -type f -print0 | sort -z | xargs -0 shasum -a 256 > "${PLUGIN_SLUG}-${VERSION}.manifest.sha256"
)
cp "${BUILD_DIR}/stage/${PLUGIN_SLUG}-${VERSION}.manifest.sha256" "${OUTPUT_DIR}/"

echo "Created ${ZIP_PATH}"
echo "Created ${OUTPUT_DIR}/${PLUGIN_SLUG}-${VERSION}.manifest.sha256"
