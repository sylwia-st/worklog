#!/usr/bin/env bash
# Instalacja w Dockerze na Ubuntu. Uruchom jako zwykły użytkownik (należący do grupy docker):
#   ./install.sh  [--port 8765] [--remind 15:45] [--no-remind] [--vpn] [--backup-dir ~/worklog-backups] [--forma f|m|n]
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
PORT=8765
REMIND=""          # pusta = zostaw godzinę z Ustawień aplikacji (domyślnie 15:45)
WITH_REMIND=1
VPN=0
BACKUP_DIR="$HOME/worklog-backups"
FORMA=""           # f = żeńska, m = męska, n = neutralna; pusta = zapytaj (albo zostaw obecną)

while [[ $# -gt 0 ]]; do
    case "$1" in
        --port) PORT="$2"; shift 2 ;;
        --remind) REMIND="$2"; WITH_REMIND=1; shift 2 ;;
        --no-remind) WITH_REMIND=0; shift ;;
        --vpn) VPN=1; shift ;;
        --backup-dir) BACKUP_DIR="$(realpath -m "$2")"; shift 2 ;;
        --forma)
            [[ "${2:-}" =~ ^[fmn]$ ]] || { echo "--forma: podaj f (żeńska), m (męska) albo n (neutralna)"; exit 1; }
            FORMA="$2"; shift 2 ;;
        *) echo "Nieznana opcja: $1"; exit 1 ;;
    esac
done

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

say "1/5 Sprawdzam Dockera"
command -v docker >/dev/null || { echo "Brak Dockera – instrukcja: https://docs.docker.com/engine/install/ubuntu/"; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "Brak wtyczki docker compose:  sudo apt install docker-compose-plugin"; exit 1; }
docker info >/dev/null 2>&1 || {
    echo "Brak dostępu do Dockera. Dodaj się do grupy:  sudo usermod -aG docker \$USER  i zaloguj ponownie."
    exit 1
}

cd "$DIR"
# zapamiętaj ustawienia – korzysta z nich skrypt ./worklog
FILES="compose.yaml"
(( VPN )) && FILES="compose.yaml:compose.vpn.yaml"
printf 'WORKLOG_PORT=%s\nCOMPOSE_FILE=%s\nWORKLOG_BACKUP_DIR=%s\n' "$PORT" "$FILES" "$BACKUP_DIR" > .env.compose
chmod +x "$DIR/worklog"

say "2/5 Buduję obraz i uruchamiam kontener"
"$DIR/worklog" up

echo -n "Czekam na start"
for _ in $(seq 1 30); do
    if [[ "$(docker inspect -f '{{.State.Health.Status}}' worklog 2>/dev/null)" == "healthy" ]]; then echo " – OK"; break; fi
    echo -n "."; sleep 2
done
[[ "$(docker inspect -f '{{.State.Health.Status}}' worklog 2>/dev/null)" == "healthy" ]] || {
    echo; echo "Kontener nie wystartował poprawnie. Logi:  docker logs worklog"; exit 1;
}

say "3/5 Forma w tekstach"
CURRENT="$(docker exec worklog php bin/console app:forma)"
if [[ -z "$FORMA" && -t 0 ]]; then
    declare -A NAME=([f]="żeńska" [m]="męska" [n]="neutralna")
    echo "W jakiej formie aplikacja ma się do Ciebie zwracać i pisać domyślne maile?"
    echo "  f) żeńska    – „Pracowałam w przerwie obiadowej”, w mailu: „pracowałam dłużej o 1h”"
    echo "  m) męska     – „Pracowałem w przerwie obiadowej”, w mailu: „pracowałem dłużej o 1h”"
    echo "  n) neutralna – „Praca w przerwie obiadowej”,      w mailu: „mój czas pracy był dłuższy o 1h”"
    while true; do
        read -rp "Wybierz f / m / n [Enter = ${NAME[$CURRENT]}]: " FORMA
        FORMA="${FORMA:-$CURRENT}"
        [[ "$FORMA" =~ ^[fmn]$ ]] && break
        echo "Wpisz f, m albo n."
    done
fi
if [[ -n "$FORMA" ]]; then
    docker exec worklog php bin/console app:forma "$FORMA"
else
    echo "Bez zmian (forma: $CURRENT). Zmienisz ją w Ustawieniach albo: ./install.sh --forma f|m|n"
fi

say "4/5 Przypomnienie i kopie zapasowe (timery systemd na hoście)"
UNIT_DIR="$HOME/.config/systemd/user"
mkdir -p "$UNIT_DIR"
chmod +x "$DIR/scripts/remind.sh" "$DIR/scripts/backup.sh"
for f in worklog-remind.service worklog-remind.timer worklog-backup.service worklog-backup.timer; do
    sed -e "s#@DIR@#$DIR#g" -e "s#@PORT@#$PORT#g" -e "s#@REMIND@#$REMIND#g" -e "s#@BACKUP_DIR@#$BACKUP_DIR#g" "$DIR/systemd/$f" > "$UNIT_DIR/$f"
done
# usuń usługę ze starej wersji (bez Dockera), jeśli była zainstalowana
if systemctl --user list-unit-files worklog.service >/dev/null 2>&1 && [[ -f "$UNIT_DIR/worklog.service" ]]; then
    systemctl --user disable --now worklog.service 2>/dev/null || true
    rm -f "$UNIT_DIR/worklog.service"
fi
systemctl --user daemon-reload
systemctl --user enable --now worklog-backup.timer >/dev/null
mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
WORKLOG_BACKUP_DIR="$BACKUP_DIR" "$DIR/scripts/backup.sh" && echo "Kopie zapasowe: co godzinę w dni robocze → $BACKUP_DIR (30 dni wstecz). Pierwsza zrobiona."
if (( WITH_REMIND )); then
    command -v notify-send >/dev/null || echo "Uwaga: brak notify-send – zainstaluj:  sudo apt install libnotify-bin"
    if [[ -n "$REMIND" ]]; then
        docker exec worklog php bin/console app:remind --set-time "$REMIND" || exit 1
    fi
    systemctl --user enable --now worklog-remind.timer >/dev/null
    echo "Pon–pt powiadomienie, jeśli raport z dziś nie jest domknięty (domyślnie o 15:45)."
    echo "Godzinę zmienisz w aplikacji: Ustawienia → Przypomnienie."
else
    systemctl --user disable --now worklog-remind.timer 2>/dev/null || true
    echo "Pominięto."
fi

say "5/5 Skrót w menu aplikacji"
APP_DIR="$HOME/.local/share/applications"
mkdir -p "$APP_DIR"
cat > "$APP_DIR/worklog.desktop" <<EOF
[Desktop Entry]
Type=Application
Name=Raport czasu
Comment=Worklogi do Jiry, podsumowanie na Slacka, maile
Exec=xdg-open http://127.0.0.1:$PORT/today
Icon=appointment-soon
Terminal=false
Categories=Office;
EOF

say "Gotowe →  http://127.0.0.1:$PORT"
echo "Zacznij od zakładki Ustawienia (adres Jiry, token, adresaci i szablony maili)."
