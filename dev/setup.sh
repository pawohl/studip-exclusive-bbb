#!/bin/sh
# Registers, migrates and activates the plugin in the dev stack and seeds
# node rooms. Safe to run repeatedly.
set -e

cd "$(dirname "$0")"
compose="docker compose -f docker-compose.yml"
plugin=public/plugins_packages/exclusivebbb/ExclusiveBBB

echo "waiting for Stud.IP ..."
waited=0
until $compose exec -T web php cli/studip config:get UNI_NAME_CLEAN >/dev/null 2>&1; do
    waited=$((waited + 3))
    if [ "$waited" -ge 600 ]; then
        echo "Stud.IP did not come up within 10 minutes" >&2
        $compose logs --tail 50 web >&2
        exit 1
    fi
    sleep 3
done

registered=$($compose exec -T web sh -c \
    'mysql -N -u "$MYSQL_USER" -h "$MYSQL_HOST" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" \
     -e "SELECT COUNT(*) FROM plugins WHERE pluginclassname = \"ExclusiveBBB\""')
if [ "$registered" = "0" ]; then
    $compose exec -T web php cli/studip plugin:register "$plugin"
fi
# the data volume is created root-owned; Apache needs it for uploads and the plugin asset cache
$compose exec -T -u root web chown -R www-data:www-data /var/www/studip/data

$compose exec -T web php cli/studip plugin:migrate ExclusiveBBB
$compose exec -T web php cli/studip plugin:activate ExclusiveBBB
$compose exec -T web php "$plugin/dev/seed.php"
$compose exec -T web php "$plugin/dev/seed_requests.php"

echo "done: http://<vm>:${EXBBB_PORT:-8000} (root@studip / testing)"
