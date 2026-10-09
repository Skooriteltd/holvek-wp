#!/usr/bin/env bash

set -euo pipefail

composer validate --no-check-publish --strict
composer audit --locked
vendor/bin/phpcs
vendor/bin/phpunit
npm run build
npm run test:integration
