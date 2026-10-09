#!/usr/bin/env bash

set -euo pipefail

readonly config_path="tests/wp-env.json"
readonly plugin_slug="holvek-wp"

cleanup() {
	./node_modules/.bin/wp-env stop --config="${config_path}" >/dev/null 2>&1 || true
}

trap cleanup EXIT

./node_modules/.bin/wp-env start --config="${config_path}"
./node_modules/.bin/wp-env run cli --config="${config_path}" wp plugin activate "${plugin_slug}"
./node_modules/.bin/wp-env run cli --config="${config_path}" wp db query "SHOW TABLES LIKE '%holvek%';" | grep -q holvek
./node_modules/.bin/wp-env run cli --config="${config_path}" wp eval-file tests/e2e-privacy.php
./node_modules/.bin/wp-env run cli --config="${config_path}" wp eval-file tests/e2e-image-optimization.php
./node_modules/.bin/wp-env run cli --config="${config_path}" wp plugin deactivate "${plugin_slug}"
./node_modules/.bin/wp-env run cli --config="${config_path}" wp plugin uninstall "${plugin_slug}" --deactivate
