# Raport czasu pracy

Lokalna aplikacja w Dockerze (PHP 8.5, Symfony 8.1, Doctrine ORM 3.7 + DoctrineBundle 3.3, SQLite) do codziennego raportowania:

1. **Jira** – wpisujesz tickety i czas, jeden przycisk wysyła worklogi (nowe dodaje, zmienione aktualizuje, usunięte kasuje).
2. **Slack** – gotowe podsumowanie per ticket do skopiowania jednym kliknięciem.
3. **Mail o nadgodzinach / krótszym dniu** – pojawia się sam, gdy bilans dnia wychodzi poza tolerancję; otwiera się w Twoim programie pocztowym z wypełnionymi adresatami, tematem i treścią.
4. **Planowane zmiany** – późniejsze przyjście, wcześniejsze wyjście, krótszy dzień: zapisujesz → dostajesz gotowy mail (z ostrzeżeniem, jeśli wysyłasz za późno).
5. **Stoper** – ▶ przy tickecie, nad którym pracujesz; przełączenie na inny ticket zatrzymuje poprzedni. Pełne minuty, bez zaokrąglania.
6. **Przypomnienie** pon–pt o wybranej godzinie (domyślnie 15:45, zmiana w Ustawieniach) – powiadomienie na pulpicie, jeśli czegoś z dzisiejszego raportu brakuje. Gdy komputer był wtedy wyłączony, pojawi się po jego włączeniu.

## Prywatność

- Port jest wystawiony tylko na `127.0.0.1` – aplikacja nie jest dostępna z sieci. Dodatkowo odrzuca żądania z obcym nagłówkiem `Host` i POST-y wysłane z innych stron.
- Jedyne połączenie wychodzące z działającego kontenera to adres Twojej Jiry. Slack i maile nie są wysyłane przez aplikację – kopiujesz tekst / zatwierdzasz mail w swoim kliencie.
- Strona nie ładuje nic z internetu (żadnych CDN, fontów, analityki).
- Dane i token leżą w wolumenie Dockera `worklog-data` (pliki z uprawnieniami 600). Kontener działa jako zwykły użytkownik, bez uprawnień (`cap_drop: ALL`, `no-new-privileges`).
- Internet (Docker Hub, packagist.org) jest potrzebny tylko przy budowaniu obrazu.

## Bezpieczeństwo wpisów

- **Autozapis** – każda zmiana trafia do bazy ok. 1 s po wpisaniu i przy zamykaniu karty; status „✓ zapisano 14:32” przy przyciskach.
- **Kopia w przeglądarce** – gdy zapis do bazy się nie uda, zmiany zostają w przeglądarce i po ponownym otwarciu dnia można je przywrócić.
- **Stoper w bazie** – czas startu jest na dysku, więc przeżywa zamknięcie przeglądarki i restart; stoper działający od innego dnia lub > 6h pyta, o której go zatrzymać.
- **Kopie co godzinę** – w dni robocze do `~/worklog-backups` (30 dni, sprawdzana integralność; powiadomienie przy błędzie). Inny katalog: `./install.sh --backup-dir /ścieżka`.
- **Odporny zapis** – SQLite w trybie WAL z `synchronous=FULL`.
- Usunięcie wiersza (✕) zatwierdza się przyciskiem „Zapisz” – autozapis niczego nie kasuje.

## Instalacja (Ubuntu)

Potrzebny Docker Engine z wtyczką compose ([instrukcja](https://docs.docker.com/engine/install/ubuntu/)) i dostęp do niego bez sudo:

```bash
sudo usermod -aG docker $USER      # potem wyloguj się i zaloguj
sudo apt install libnotify-bin     # powiadomienia (zwykle już jest)

tar xzf worklog-tool.tar.gz && cd worklog-tool
./install.sh                       # opcje: --port 8765  --remind 16:00  --no-remind  --vpn  --forma f|m|n
```

Instalator zapyta o **formę w tekstach**: żeńską („Pracowałam w przerwie obiadowej”, w mailu „pracowałam dłużej o 1h”), męską („Pracowałem…”) albo neutralną („Praca w przerwie obiadowej”, „mój czas pracy był dłuższy o 1h”). Później zmienisz ją w Ustawieniach → Ogólne.

Potem otwórz **http://127.0.0.1:8765** (albo „Raport czasu” z menu aplikacji) i zacznij od **Ustawień**.
Kontener startuje sam po restarcie komputera.

### VPN / Jira w sieci firmowej

Jeśli „Sprawdź połączenie” zgłasza brak połączenia, a Jira działa w przeglądarce (np. tylko przez VPN lub firmowy DNS), zainstaluj z `--vpn`:

```bash
./install.sh --vpn
```

Kontener użyje wtedy sieci hosta, a serwer będzie słuchał wyłącznie na `127.0.0.1`.

### Jira – token

Token generujesz sam, z uprawnieniami swojego konta:

| Twoja Jira | E-mail | Token |
|---|---|---|
| Cloud (`*.atlassian.net`) | Twój e-mail w Atlassian | id.atlassian.com → Security → Create and manage API tokens |
| Server / Data Center | **puste** | Avatar → Profile → Personal Access Tokens → Create token |

Jeśli nie widzisz tej opcji, admin wyłączył tokeny – poproś go o Personal Access Token / API token z Twoimi uprawnieniami.

## Codzienny przebieg

1. **Dzień** → godziny od–do (domyślne są już wpisane) + wiersze: ticket, czas (`1h 30m`, `1.5`, `90m`, `1:30`), opis.
   „Skopiuj tickety z poprzedniego dnia” przenosi same numery.
2. **Zapisz i wyślij do Jiry.** Kropka przy wierszu: zielona = w Jirze, żółta = zmieniony po wysłaniu, czerwona = błąd (najedź, żeby zobaczyć treść).
3. **Kopiuj na Slacka** → wklej w kanał.
4. Jeśli pojawi się panel maila → **Otwórz w programie pocztowym** → Wyślij.

Worklogi dostają kolejne godziny startu od początku dnia i omijają przerwę obiadową (8:00–10:00, 10:00–12:00, 13:00–15:00…), więc nie nakładają się w kalendarzu/Tempo.

## Szablony

Teksty Slacka i maili edytujesz w Ustawieniach. Zmienne:

- dzień: `{data} {data_iso} {dzien} {od} {do} {przerwa} {obecnosc} {zalogowane} {norma} {roznica} {lista} {podpis}`
- linia Slacka dodatkowo: `{ticket} {czas} {opis} {tytul}`
- planowana zmiana: `{data} {dzien} {typ} {od} {do} {opis} {powod} {powod_linia} {podpis}`

Nieznana zmienna zostaje w tekście dosłownie – łatwo wyłapać literówkę.
Domyślnie: norma **7h**, praca 8:00–16:00, stała przerwa obiadowa **12:00–13:00** (zmienisz w Ustawieniach → Dzień pracy).
Bilans liczony jest z godzin obecności: od–do minus ta część obiadu, która wypada w godzinach pracy, minus ewentualna dodatkowa przerwa; gdy godzin nie podasz – z sumy czasu w ticketach.

**Awaria w porze obiadu:** zaznacz w dniu pole pracy w przerwie obiadowej i wpisz przerwę, która faktycznie była – czasem (`1h`, `20m + 40m`) albo godzinami (`13:30-13:50, 15:00-15:40`). Obiad nie odliczy się wtedy automatycznie, a worklogi w Jirze ominą wpisane godziny przerw.

## Obsługa

```bash
./worklog status     # stan kontenera
./worklog logs       # logi na żywo
./worklog restart
./worklog backup     # dodatkowa kopia teraz (automatyczne są co godzinę w ~/worklog-backups)
./worklog restore ~/worklog-backups/worklog-RRRRMMDD-GGMM.db   # przed nadpisaniem robi kopię obecnego stanu
./worklog update     # przebudowa obrazu na najnowszych łatkach PHP 8.5 / Symfony 8.1
./worklog remind     # co zostało do zrobienia dzisiaj
./worklog down       # zatrzymanie (dane zostają w wolumenie)
./worklog uninstall  # usunięcie wszystkiego (kontener, dane, timery, skrót) – najpierw ostatnia kopia bazy
```

Godzinę przypomnienia zmieniasz w aplikacji: **Ustawienia → Przypomnienie** (puste pole = bez przypomnienia).
Zmiana portu: uruchom ponownie `./install.sh --port 8766` – dane zostają.

Całkowite usunięcie: `./worklog uninstall`. Usuwa kontener, obraz, wolumen z danymi, timery systemd i skrót w menu; przedtem robi ostatnią kopię bazy. Kopie zapasowe i katalog z kodem zostają. Potem unieważnij token w Jirze.

## Wersje

| Składnik | Wersja |
|---|---|
| PHP | 8.5 (`php:8.5-cli-alpine`) |
| Symfony | 8.1.* |
| Doctrine ORM / DBAL | ^3.7 / ^4.3 |
| DoctrineBundle | ^3.3 |
| Twig | ^3.21 |

Dokładne wersje pakietów są przypięte w `composer.lock` – `./worklog update` podnosi je do najnowszych łatek w ramach `composer.json` i przebudowuje obraz.

Symfony 8.1 ma wsparcie bezpieczeństwa do stycznia 2027 – później zmień w `composer.json` `8.1.*` na kolejną wersję (8.2 / 8.4 LTS) i zrób `./worklog update`.
Jeśli wolisz rzadsze aktualizacje, możesz zamiast tego użyć LTS: `7.4.*` (wsparcie do listopada 2029).

## Testy logiki

```bash
docker exec worklog php tests/domain_test.php
docker exec worklog php tests/services_test.php
cd tests/browser && npm install && npm test     # autozapis, szkic, stoper – w symulowanej przeglądarce
```

## Instrukcja PDF

`docs/raport-czasu-instrukcja.pdf` jest generowana z `docs/instrukcja/build.py` (treść jest w tym pliku). Po zmianie:

```bash
docker run --rm -v "$PWD":/w -v /usr/share/fonts/truetype/dejavu:/fonts:ro -w /w python:3.12-slim \
    sh -c "pip install -q reportlab && python docs/instrukcja/build.py /fonts"
```
# worklog
