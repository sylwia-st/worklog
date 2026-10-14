#!/bin/sh
set -eu
umask 077

# Sekret aplikacji generowany przy pierwszym starcie i trzymany w wolumenie z danymi.
if [ ! -s /data/.app_secret ]; then
    php -r 'echo bin2hex(random_bytes(32));' > /data/.app_secret
fi
APP_SECRET="$(cat /data/.app_secret)"
export APP_SECRET

# Tworzy/aktualizuje schemat bazy. Uwaga: Doctrine ORM 3 synchronizuje schemat w pełni – kolumna lub tabela
# usunięta/przemianowana w encjach zostanie skasowana razem z danymi. Dlatego gdy coś się zmieni, najpierw kopia
# do /data/kopie (10 ostatnich); jeśli się nie uda, kontener nie startuje, a baza zostaje nietknięta.
if ! php bin/console app:db schema-backup /data/kopie; then
    echo "Kopia przed zmianą struktury bazy się nie udała – przerywam start, baza nie została zmieniona." >&2
    exit 1
fi
php bin/console doctrine:schema:update --force --no-interaction --quiet

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# Domyślnie 0.0.0.0:8080 wewnątrz kontenera – na hoście port jest wystawiony wyłącznie na 127.0.0.1 (compose.yaml).
# W trybie network_mode: host ustaw WORKLOG_LISTEN=127.0.0.1:8765, żeby nie wystawić aplikacji do sieci.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-2}"
exec php -S "${WORKLOG_LISTEN:-0.0.0.0:8080}" -t public public/index.php
