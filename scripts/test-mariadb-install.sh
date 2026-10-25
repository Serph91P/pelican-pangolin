#!/usr/bin/env bash
# Disposable native rc.2 install/retry contract; never connects to an existing DB.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
name="pangolin-issue4-$$"
cleanup() {
    if [[ -n "${panel:-}" ]]; then docker stop "$panel" >/dev/null 2>&1 || true; fi
    docker stop "$name-db" >/dev/null 2>&1 || true
    for attempt in {1..10}; do
        if docker network rm "$name" >/dev/null 2>&1; then return; fi
        sleep 1
    done
    printf 'Could not clean up test network %s\n' "$name" >&2
}
if [[ $# -eq 0 ]]; then
    set -- fresh partial data complete duplicate orphan wrong-column missing-fk wrong-fk wrong-index extra-column wrong-extra wrong-check
fi
for scenario in "$@"; do
    case "$scenario" in
        fresh|partial|data|complete|duplicate|orphan|wrong-column|missing-fk|wrong-fk|wrong-index|extra-column|wrong-extra|wrong-check) ;;
        *) printf 'Unknown test scenario: %s\n' "$scenario" >&2; exit 1 ;;
    esac
done
bash "$root/scripts/build-plugin.sh"
docker network create "$name" >/dev/null
trap cleanup EXIT
docker run --rm -d --name "$name-db" --network "$name" --network-alias database \
    -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 mariadb:11.8.9 >/dev/null
for attempt in {1..60}; do
    if docker exec "$name-db" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then break; fi
    sleep 1
done
docker exec "$name-db" healthcheck.sh --connect --innodb_initialized
for scenario in "$@"; do
    panel="$name-panel-$scenario"
    docker exec "$name-db" mariadb -e "CREATE DATABASE \`$scenario\`;"
    docker run --rm -d --name "$panel" --network "$name" \
        -e APP_ENV=local -e APP_DEBUG=false -e APP_URL=http://localhost \
        -e DB_CONNECTION=mysql -e DB_HOST=database -e DB_DATABASE="$scenario" -e DB_USERNAME=root -e DB_PASSWORD= \
        -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
        -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
        --entrypoint sh ghcr.io/pelican/panel:v1.0.0-rc.2 -c 'sleep 600' >/dev/null
    docker exec "$panel" mkdir /var/www/html/test-fixture
    tar -C "$root" -cf - build/pelican-pangolin.zip tests/MariaDb | docker exec -i "$panel" tar -xf - -C /var/www/html/test-fixture
    docker exec "$panel" sh -ec '
        # Seven real core migration batches make the partial plugin batch exactly 8.
        count=0
        for migration in database/migrations/*.php; do
            [ "$count" -eq 6 ] && break
            php artisan migrate --path="$migration" --force --no-interaction > /dev/null
            count=$((count + 1))
        done
        php artisan migrate --force --no-interaction > /dev/null
        unzip -q /var/www/html/test-fixture/build/pelican-pangolin.zip -d plugins
        php /var/www/html/test-fixture/tests/MariaDb/native-install.php "$DB_DATABASE"
    '
    docker stop "$panel" >/dev/null
done
