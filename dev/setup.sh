#!/bin/sh
# Registers, migrates and activates the plugin in the dev stack and seeds
# node rooms. Safe to run repeatedly.
set -e

cd "$(dirname "$0")"
compose="docker compose -f docker-compose.yml"
plugin=public/plugins_packages/exclusivebbb/ExclusiveBBB

echo "waiting for Stud.IP ..."
until $compose exec -T web php cli/studip config:get UNI_NAME_CLEAN >/dev/null 2>&1; do
    sleep 3
done

if ! $compose exec -T web php cli/studip plugin:info ExclusiveBBB >/dev/null 2>&1; then
    $compose exec -T web php cli/studip plugin:register "$plugin"
fi
$compose exec -T web php cli/studip plugin:migrate ExclusiveBBB
$compose exec -T web php cli/studip plugin:activate ExclusiveBBB
$compose exec -T web php "$plugin/dev/seed.php"

echo "done: http://<vm>:8000 (root@studip / testing)"
