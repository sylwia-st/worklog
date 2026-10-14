# Instalacja na Windowsie (Docker Desktop) – odpowiednik install.sh. Najprościej: dwuklik na install.cmd.
# Z opcjami (w PowerShellu albo cmd, w katalogu z narzędziem):
#   .\install.cmd [-Port 8765] [-Remind 15:45] [-NoRemind] [-BackupDir C:\kopie] [-Forma f|m|n]
# Plik musi zostać zapisany jako UTF-8 z BOM (Windows PowerShell 5.1).
param(
    [int]$Port = 8765,
    [string]$Remind = '',      # pusta = zostaw godzinę z Ustawień aplikacji (domyślnie 15:45)
    [switch]$NoRemind,
    [string]$BackupDir = '',   # pusta = %USERPROFILE%\worklog-backups
    [string]$Forma = ''        # f = żeńska, m = męska, n = neutralna; pusta = zapytaj (albo zostaw obecną)
)

. (Join-Path $PSScriptRoot 'worklog.ps1')   # wspólne funkcje (bez uruchamiania poleceń)

function Say([string]$Text) { Write-Host ''; Write-Host $Text -ForegroundColor Cyan }
function Stop-Install([string]$Text) { Write-Host $Text -ForegroundColor Red; exit 1 }

$previousEncoding = [Console]::OutputEncoding
try {
    try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }   # polskie znaki z dockera

    if ($Port -lt 1 -or $Port -gt 65535) { Stop-Install '-Port: podaj numer portu od 1 do 65535.' }
    $Forma = $Forma.Trim().ToLower()
    if ($Forma -and $Forma -notmatch '^[fmn]$') { Stop-Install '-Forma: podaj f (żeńska), m (męska) albo n (neutralna).' }
    if (-not $BackupDir) { $BackupDir = Join-Path $HOME 'worklog-backups' }
    $BackupDir = Resolve-UserPath $BackupDir

    Say '1/5 Sprawdzam Dockera'
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        Stop-Install 'Brak Dockera – zainstaluj Docker Desktop: https://docs.docker.com/desktop/setup/install/windows-install/'
    }
    if ((Invoke-Native docker @('compose', 'version')).Code -ne 0) { Stop-Install 'Brak docker compose – zaktualizuj Docker Desktop.' }
    $info = Invoke-Native docker @('info', '--format', '{{.OSType}}')
    if ($info.Code -ne 0) {
        Stop-Install ("Docker nie odpowiada. Uruchom Docker Desktop, poczekaj, aż wystartuje, i spróbuj ponownie.`n" + (Get-ErrorText $info))
    }
    if ($info.Output -ne 'linux') {
        Stop-Install 'Docker Desktop działa w trybie kontenerów Windows. Kliknij prawym przyciskiem ikonę Dockera przy zegarze → „Switch to Linux containers…” i uruchom instalację ponownie.'
    }

    Say '2/5 Buduję obraz i uruchamiam kontener'
    # pliki pobrane z internetu – bez tego Windows pytałby przy każdym uruchomieniu
    if ($OnWindows) { Get-ChildItem -LiteralPath $Dir -Recurse -Include '*.ps1', '*.cmd' | Unblock-File }
    # zapamiętaj ustawienia – korzysta z nich worklog.cmd i agent w tle
    Write-Config @{
        WORKLOG_PORT       = "$Port"
        COMPOSE_FILE       = 'compose.yaml'
        WORKLOG_BACKUP_DIR = $BackupDir
        WORKLOG_REMIND     = $(if ($NoRemind) { '0' } else { '1' })
    }
    Invoke-Compose up -d --build
    if ($LASTEXITCODE -ne 0) { Stop-Install 'Nie udało się zbudować albo uruchomić kontenera – szczegóły powyżej.' }

    Write-Host -NoNewline 'Czekam na start'
    $healthy = $false
    for ($i = 0; $i -lt 30; $i++) {
        if ((Get-ContainerHealth) -eq 'healthy') { $healthy = $true; break }
        Write-Host -NoNewline '.'
        Start-Sleep -Seconds 2
    }
    if (-not $healthy) { Write-Host ''; Stop-Install "Kontener nie wystartował poprawnie. Logi:  docker logs $Container" }
    Write-Host ' – OK'

    Say '3/5 Forma w tekstach'
    $current = (Invoke-Native docker @('exec', $Container, 'php', 'bin/console', 'app:forma')).Output
    if (-not $Forma -and [Environment]::UserInteractive -and -not [Console]::IsInputRedirected) {
        $names = @{ f = 'żeńska'; m = 'męska'; n = 'neutralna' }
        Write-Host 'W jakiej formie aplikacja ma się do Ciebie zwracać i pisać domyślne maile?'
        Write-Host '  f) żeńska    – „Pracowałam w przerwie obiadowej”, w mailu: „pracowałam dłużej o 1h”'
        Write-Host '  m) męska     – „Pracowałem w przerwie obiadowej”, w mailu: „pracowałem dłużej o 1h”'
        Write-Host '  n) neutralna – „Praca w przerwie obiadowej”,      w mailu: „mój czas pracy był dłuższy o 1h”'
        while ($true) {
            $Forma = "$(Read-Host "Wybierz f / m / n [Enter = $($names[$current])]")".Trim().ToLower()
            if (-not $Forma) { $Forma = $current }
            if ($Forma -match '^[fmn]$') { break }
            Write-Host 'Wpisz f, m albo n.'
        }
    }
    if ($Forma) {
        & docker exec $Container php bin/console app:forma $Forma
    } else {
        Write-Host "Bez zmian (forma: $current). Zmienisz ją w Ustawieniach albo: .\install.cmd -Forma f|m|n"
    }

    Say '4/5 Przypomnienie i kopie zapasowe (w tle, startują razem z Windowsem)'
    try {
        if (Invoke-ScheduledBackup) { Set-LastBackupRun (Get-Date) }
        Write-Host "Kopie zapasowe: co godzinę w dni robocze → $BackupDir (30 dni wstecz). Pierwsza zrobiona."
    } catch {
        Write-Host "Uwaga: pierwsza kopia zapasowa się nie udała: $($_.Exception.Message)" -ForegroundColor Yellow
    }
    if (-not $NoRemind) {
        if ($Remind) {
            & docker exec $Container php bin/console app:remind --set-time $Remind
            if ($LASTEXITCODE -ne 0) { exit 1 }
        }
        Write-Host 'Pon–pt powiadomienie, jeśli raport z dziś nie jest domknięty (domyślnie o 15:45).'
        Write-Host 'Godzinę zmienisz w aplikacji: Ustawienia → Przypomnienie.'
    } else {
        Write-Host 'Przypomnienie pominięte – w tle robią się tylko kopie zapasowe.'
    }
    Install-Agent
    if (-not $NoRemind -and $OnWindows) {
        # próbne powiadomienie – od razu widać, czy Windows je pokazuje
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Dir 'worklog.ps1') notify-test
        Write-Host 'Wysłano próbne powiadomienie. Jeśli go nie widać: Ustawienia Windows → System → Powiadomienia → Windows PowerShell.'
    }

    Say '5/5 Skrót w menu Start'
    Install-MenuShortcut $Port

    Say "Gotowe →  http://127.0.0.1:$Port"
    Write-Host 'Zacznij od zakładki Ustawienia (adres Jiry, token, adresaci i szablony maili).'
    if ($OnWindows) { Start-Process "http://127.0.0.1:$Port/" }
} finally {
    try { [Console]::OutputEncoding = $previousEncoding } catch { }
}
