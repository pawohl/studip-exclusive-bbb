#!/bin/sh
# Runs unit, integration and E2E tests against a running dev stack
# (docker compose -f dev/docker-compose.yml up -d && dev/setup.sh).
# Used locally and by the GitHub Actions workflow. Respects COMPOSE_PROJECT_NAME.
#
#   dev/run-tests.sh            # all suites
#   dev/run-tests.sh unit       # only one suite: unit, integration or e2e
set -eu

cd "$(dirname "$0")"
root=$(cd .. && pwd)
compose="docker compose -f docker-compose.yml"
project=${COMPOSE_PROJECT_NAME:-exbbb}
plugin=/var/www/studip/public/plugins_packages/exclusivebbb/ExclusiveBBB
playwright=mcr.microsoft.com/playwright:v1.64.0-noble
suite=${1:-all}

mkdir -p screenshots

if [ ! -x "$root/vendor/bin/phpunit" ]; then
    echo "== installing PHPUnit"
    docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
        -v "$root":/app -w /app composer:2 install --no-interaction --no-progress --ignore-platform-reqs
fi

if [ "$suite" = all ] || [ "$suite" = unit ]; then
    echo "== unit tests (PHP of the Stud.IP container)"
    $compose exec -T -w "$plugin" web php vendor/bin/phpunit --do-not-cache-result
fi

# the integration test leaves a running booking for the E2E tests (screenshots/now.json)
if [ "$suite" = all ] || [ "$suite" = integration ] || { [ "$suite" = e2e ] && [ ! -f screenshots/now.json ]; }; then
    echo "== integration tests"
    $compose exec -T web php "$plugin/tests/integration/booking_flow.php" --keep-now
    docker cp "$($compose ps -q web)":/tmp/exbbb-now.json screenshots/now.json
fi

if [ "$suite" = all ] || [ "$suite" = e2e ]; then
    echo "== E2E tests (Playwright)"
    docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
        -v "$root":/work -w /work/tests/e2e "$playwright" npm ci --no-audit --no-fund
    docker run --rm --network "${project}_default" -u "$(id -u):$(id -g)" -e HOME=/tmp \
        -v "$root":/work -w /work/tests/e2e "$playwright" npx playwright test
fi
