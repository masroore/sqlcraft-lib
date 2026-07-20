#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")"
composer install --no-interaction --prefer-dist
vendor/bin/rector process --dry-run ../src --config ../rector.php
