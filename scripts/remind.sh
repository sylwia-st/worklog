#!/usr/bin/env bash
# Uruchamiane na hoście przez timer systemd co minutę (pon–pt). Kontener sam decyduje, czy to już godzina
# przypomnienia z Ustawień – jeśli tak i czegoś brakuje w raporcie, pokazujemy powiadomienie na pulpicie.
set -uo pipefail

PORT="${WORKLOG_PORT:-8765}"
msg="$(docker exec worklog php bin/console app:remind --scheduled --no-interaction 2>/dev/null)" || exit 0
[[ -z "$msg" ]] && exit 0

notify-send --app-name=Worklog --icon=appointment-soon "Raport czasu pracy" "$msg
http://127.0.0.1:$PORT/today"
