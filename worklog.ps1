# Obsługa aplikacji na Windowsie – odpowiednik ./worklog z Linuksa. Uruchamiaj przez worklog.cmd:
#   .\worklog.cmd [up|down|restart|logs|status|update|backup [PLIK]|restore PLIK|remind|uninstall|console ...]
#
# Ten sam plik to:
#  - biblioteka funkcji dla install.ps1 (dołącza go przez „. worklog.ps1” – wtedy żadne polecenie się nie wykonuje),
#  - agent w tle („worklog.ps1 agent”): przypomnienie i kopie co godzinę. Startuje z Windowsem przez skrót
#    w Autostarcie – zamiast timerów systemd z wersji linuksowej.
#
# Pisane pod Windows PowerShell 5.1 (jest w każdym Windowsie 10/11). Plik musi zostać zapisany jako UTF-8 z BOM,
# inaczej PowerShell 5.1 odczyta polskie znaki jako krzaczki.

$Dir = $PSScriptRoot
$Container = 'worklog'
$Volume = 'worklog-data'
$Image = 'worklog-tool:latest'
$EnvFile = Join-Path $Dir '.env.compose'
$ComposeFile = Join-Path $Dir 'compose.yaml'
$OnWindows = $env:OS -eq 'Windows_NT'
# Powiadomienia idą w imieniu Windows PowerShell – ten identyfikator aplikacji jest zarejestrowany w każdym Windowsie.
$ToastAppId = '{1AC14E77-02E7-4E5D-B744-2EB1AE5198B7}\WindowsPowerShell\v1.0\powershell.exe'

# ---------- konfiguracja (.env.compose – ten sam plik co na Linuksie) ----------

function Read-Config {
    $cfg = @{
        WORKLOG_PORT       = '8765'
        COMPOSE_FILE       = 'compose.yaml'
        WORKLOG_BACKUP_DIR = (Join-Path $HOME 'worklog-backups')
        WORKLOG_REMIND     = '1'
    }
    if (Test-Path -LiteralPath $EnvFile) {
        foreach ($line in [IO.File]::ReadAllLines($EnvFile)) {
            if ($line -match '^\s*([A-Z_]+)=(.*)$') { $cfg[$Matches[1]] = $Matches[2].Trim() }
        }
    }
    return $cfg
}

function Write-Config([hashtable]$Cfg) {
    $lines = foreach ($key in 'WORKLOG_PORT', 'COMPOSE_FILE', 'WORKLOG_BACKUP_DIR', 'WORKLOG_REMIND') { '{0}={1}' -f $key, $Cfg[$key] }
    # bez BOM – docker compose czyta ten plik jako --env-file
    [IO.File]::WriteAllText($EnvFile, (($lines -join "`n") + "`n"), (New-Object Text.UTF8Encoding $false))
}

# Ścieżka podana przez użytkownika – względna liczy się od katalogu, w którym wpisał polecenie.
function Resolve-UserPath([string]$Path) {
    return $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Path)
}

# ---------- Docker ----------

# Uruchamia program bez wyświetlania wyniku; zwraca kod wyjścia, stdout (Output) i stderr (Errors).
function Invoke-Native([string]$Exe, [string[]]$Arguments) {
    if (-not (Get-Command $Exe -ErrorAction SilentlyContinue)) {
        return [pscustomobject]@{ Code = 127; Output = ''; Errors = "Nie znaleziono programu $Exe." }
    }
    $out = New-Object System.Collections.Generic.List[string]
    $err = New-Object System.Collections.Generic.List[string]
    & $Exe @Arguments 2>&1 | ForEach-Object {
        if ($_ -is [System.Management.Automation.ErrorRecord]) { $err.Add("$_") } else { $out.Add("$_") }
    }
    return [pscustomobject]@{ Code = $LASTEXITCODE; Output = ($out -join "`n").Trim(); Errors = ($err -join "`n").Trim() }
}

# docker compose z wynikiem wprost na ekran (postęp budowania, logi)
function Invoke-Compose {
    if (-not (Test-Path -LiteralPath $EnvFile)) { Write-Config (Read-Config) }
    & docker compose --env-file $EnvFile -f $ComposeFile @args
}

function Test-ContainerRunning {
    return (Invoke-Native docker @('inspect', '-f', '{{.State.Running}}', $Container)).Output -eq 'true'
}

function Get-ContainerHealth {
    return (Invoke-Native docker @('inspect', '-f', '{{.State.Health.Status}}', $Container)).Output
}

function Get-ErrorText($Result) {
    return ("$($Result.Errors)`n$($Result.Output)").Trim()
}

# ---------- kopia i przywracanie ----------

# Kopia bazy do pliku na tym komputerze. Plik pojawia się dopiero, gdy jest kompletny.
function Backup-Database([string]$Out) {
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Out) | Out-Null
    # VACUUM INTO + sprawdzenie integralności robi aplikacja w kontenerze; tu tylko przenosimy plik
    $r = Invoke-Native docker @('exec', $Container, 'php', 'bin/console', 'app:db', 'backup', '/data/.backup.db')
    if ($r.Code -ne 0) { throw (Get-ErrorText $r) }
    $r = Invoke-Native docker @('cp', "${Container}:/data/.backup.db", "$Out.part")
    if ($r.Code -ne 0) { throw ('Nie udało się skopiować kopii z kontenera: ' + (Get-ErrorText $r)) }
    $null = Invoke-Native docker @('exec', $Container, 'rm', '-f', '/data/.backup.db')
    Move-Item -LiteralPath "$Out.part" -Destination $Out -Force
}

function Restore-Database([string]$File) {
    $r = Invoke-Native docker @('cp', $File, "${Container}:/data/.restore.db")
    if ($r.Code -ne 0) { throw ('Nie udało się skopiować pliku do kontenera: ' + (Get-ErrorText $r)) }
    try {
        $r = Invoke-Native docker @('exec', $Container, 'php', 'bin/console', 'app:db', 'restore', '/data/.restore.db')
        if ($r.Code -ne 0) { throw (Get-ErrorText $r) }
    } finally {
        $null = Invoke-Native docker @('exec', $Container, 'rm', '-f', '/data/.restore.db')
    }
    # kopia sprzed zmiany schematu bazy: brakujące kolumny dochodzą od razu, a nie dopiero przy restarcie kontenera
    $null = Invoke-Native docker @('exec', $Container, 'php', 'bin/console', 'doctrine:schema:update', '--force', '--no-interaction', '--quiet')
}

# Kopia jak timer na Linuksie: worklog-RRRRMMDD-GGMM.db, bez duplikatów, z ostatnich 30 dni.
# Zwraca $false, gdy kontener nie działa (nic nie zrobiono); błąd kopii rzuca wyjątek.
function Invoke-ScheduledBackup {
    $dest = (Read-Config).WORKLOG_BACKUP_DIR
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    if (-not (Test-ContainerRunning)) { return $false }

    $new = Join-Path $dest ('worklog-{0:yyyyMMdd-HHmm}.db' -f (Get-Date))
    Backup-Database $new

    # identyczna jak poprzednia → usuń duplikat (zostaje starsza z tą samą treścią)
    $prev = Get-ChildItem -LiteralPath $dest -Filter 'worklog-*.db' -File |
        Where-Object { $_.FullName -ne $new } | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($prev -and (Get-FileHash -LiteralPath $new).Hash -eq (Get-FileHash -LiteralPath $prev.FullName).Hash) {
        Remove-Item -LiteralPath $new
    }

    $days = 30
    if ($env:WORKLOG_BACKUP_DAYS) { $days = [int]$env:WORKLOG_BACKUP_DAYS }
    Get-ChildItem -LiteralPath $dest -Filter 'worklog-*.db' -File |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$days) } | Remove-Item
    # pozostałości po przerwanej kopii
    Get-ChildItem -LiteralPath $dest -Filter '*.part' -File |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddHours(-1) } | Remove-Item
    return $true
}

# ---------- agent w tle: przypomnienie i kopie ----------

function Get-StatePath([string]$Name) {
    return Join-Path (Read-Config).WORKLOG_BACKUP_DIR $Name
}

function Get-LastBackupRun {
    $file = Get-StatePath '.last-run'
    if (Test-Path -LiteralPath $file) {
        try {
            return [datetime]::Parse((Get-Content -LiteralPath $file -Raw).Trim(), [Globalization.CultureInfo]::InvariantCulture, 'RoundtripKind')
        } catch { }
    }
    return [datetime]::MinValue
}

function Set-LastBackupRun([datetime]$At) {
    Set-Content -LiteralPath (Get-StatePath '.last-run') -Value $At.ToString('o') -Encoding ASCII
}

function Write-AgentLog([string]$Message) {
    $file = Get-StatePath '.agent.log'
    try {
        if ((Test-Path -LiteralPath $file) -and (Get-Item -LiteralPath $file -Force).Length -gt 256KB) {
            Set-Content -LiteralPath $file -Value (Get-Content -LiteralPath $file -Tail 200) -Encoding UTF8
        }
        Add-Content -LiteralPath $file -Value ('{0:yyyy-MM-dd HH:mm:ss} {1}' -f (Get-Date), $Message) -Encoding UTF8
    } catch { }
}

function Test-Weekday([datetime]$At) {
    return $At.DayOfWeek -ne 'Saturday' -and $At.DayOfWeek -ne 'Sunday'
}

# Ostatnia pora kopii przed $Now: pon–pt 7:55, 8:55 … 20:55 (jak timer systemd na Linuksie).
function Get-LastBackupSlot([datetime]$Now) {
    for ($d = 0; $d -le 7; $d++) {
        $day = $Now.Date.AddDays(-$d)
        if (-not (Test-Weekday $day)) { continue }
        for ($h = 20; $h -ge 7; $h--) {
            $slot = $day.AddHours($h).AddMinutes(55)
            if ($slot -le $Now) { return $slot }
        }
    }
    return $null
}

function Invoke-AgentTick([datetime]$Now) {
    $cfg = Read-Config
    # przypomnienie: pon–pt co minutę 6:00–21:59; czy już pora, decyduje aplikacja (godzina z Ustawień, raz dziennie)
    if ($cfg.WORKLOG_REMIND -ne '0' -and (Test-Weekday $Now) -and $Now.Hour -ge 6 -and $Now.Hour -lt 22) {
        $r = Invoke-Native docker @('exec', $Container, 'php', 'bin/console', 'app:remind', '--scheduled', '--no-interaction')
        if ($r.Code -eq 0 -and $r.Output) {
            Show-Notification 'Raport czasu pracy' $r.Output ('http://127.0.0.1:{0}/today' -f $cfg.WORKLOG_PORT)
        }
    }
    # kopia: przegapiona (komputer wyłączony, Docker jeszcze nie wystartował) robi się przy najbliższej okazji
    $slot = Get-LastBackupSlot $Now
    if ($null -ne $slot -and (Get-LastBackupRun) -lt $slot) {
        try {
            if (Invoke-ScheduledBackup) { Set-LastBackupRun $Now }
        } catch {
            Set-LastBackupRun $Now   # kolejna próba o następnej pełnej godzinie, bez powiadomień co minutę
            $message = "$($_.Exception.Message)"
            if ($message.Length -gt 300) { $message = $message.Substring(0, 300) }
            Write-AgentLog "Kopia zapasowa: $message"
            Show-Notification 'Raport czasu: kopia zapasowa się nie udała' $message
        }
    }
}

function Start-AgentLoop {
    $created = $false
    $mutex = [System.Threading.Mutex]::new($true, 'Local\WorklogAgent', [ref]$created)
    if (-not $created) { return }   # agent już działa
    try {
        while ($true) {
            try { Invoke-AgentTick (Get-Date) } catch { Write-AgentLog "$($_.Exception.Message)" }
            Start-Sleep -Seconds (61 - (Get-Date).Second)   # tuż po następnej pełnej minucie
        }
    } finally {
        $mutex.Dispose()
    }
}

# ---------- Windows: powiadomienia, skróty, agent ----------

function Show-Notification([string]$Title, [string]$Text, [string]$Url = '') {
    if (-not $OnWindows) { Write-Host "[powiadomienie] $Title – $Text"; return }
    $null = [Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime]
    $null = [Windows.Data.Xml.Dom.XmlDocument, Windows.Data.Xml.Dom.XmlDocument, ContentType = WindowsRuntime]
    $launch = ''
    $actions = ''
    if ($Url) {
        $link = [Security.SecurityElement]::Escape($Url)
        $launch = " activationType=`"protocol`" launch=`"$link`""
        $actions = "<actions><action content=`"Otwórz raport`" activationType=`"protocol`" arguments=`"$link`"/></actions>"
    }
    $xml = New-Object Windows.Data.Xml.Dom.XmlDocument
    $xml.LoadXml(("<toast$launch><visual><binding template=`"ToastGeneric`"><text>{0}</text><text>{1}</text></binding></visual>{2}</toast>" -f
        [Security.SecurityElement]::Escape($Title), [Security.SecurityElement]::Escape($Text), $actions))
    [Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier($ToastAppId).Show([Windows.UI.Notifications.ToastNotification]::new($xml))
}

function New-Shortcut([string]$Path, [string]$Target, [string]$Arguments, [string]$Icon = '', [int]$WindowStyle = 1, [string]$Description = '') {
    $link = (New-Object -ComObject WScript.Shell).CreateShortcut($Path)
    $link.TargetPath = $Target
    $link.Arguments = $Arguments
    $link.WorkingDirectory = $Dir
    $link.WindowStyle = $WindowStyle
    $link.Description = $Description
    if ($Icon) { $link.IconLocation = $Icon }
    $link.Save()
}

function Get-AgentLinkPath { return Join-Path ([Environment]::GetFolderPath('Startup')) 'Raport czasu (w tle).lnk' }
function Get-MenuLinkPath { return Join-Path ([Environment]::GetFolderPath('Programs')) 'Raport czasu.lnk' }

function Stop-Agent {
    if (-not $OnWindows) { return }
    $agents = @(Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" |
        Where-Object { $_.CommandLine -match 'worklog\.ps1"?\s+agent\s*$' })
    foreach ($p in $agents) { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue }
    foreach ($p in $agents) { Wait-Process -Id $p.ProcessId -Timeout 10 -ErrorAction SilentlyContinue }
}

# Skrót w Autostarcie: agent startuje przy logowaniu, ukryty. conhost.exe wprost – żeby Windows Terminal
# (domyślny terminal w Windows 11) nie otwierał okna, którego -WindowStyle Hidden już nie schowa.
function Install-Agent {
    if (-not $OnWindows) { Write-Host 'Pomijam agenta w tle – to nie Windows.'; return }
    Stop-Agent
    New-Shortcut -Path (Get-AgentLinkPath) -Target (Join-Path $env:SystemRoot 'System32\conhost.exe') `
        -Arguments ('powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}" agent' -f (Join-Path $Dir 'worklog.ps1')) `
        -WindowStyle 7 -Description 'Raport czasu – przypomnienie i kopie zapasowe w tle'
    Start-Process -FilePath (Get-AgentLinkPath)
}

function Remove-Agent {
    Stop-Agent
    if ($OnWindows) { Remove-Item -LiteralPath (Get-AgentLinkPath) -ErrorAction SilentlyContinue }
}

function Install-MenuShortcut([int]$Port) {
    if (-not $OnWindows) { Write-Host 'Pomijam skrót w menu Start – to nie Windows.'; return }
    New-Shortcut -Path (Get-MenuLinkPath) -Target (Join-Path $env:SystemRoot 'explorer.exe') -Arguments "http://127.0.0.1:$Port/today" `
        -Icon ((Join-Path $env:SystemRoot 'System32\timedate.cpl') + ',0') -Description 'Worklogi do Jiry, podsumowanie na Slacka, maile'
}

# ---------- polecenia ----------

function Confirm-Action([string]$Question) {
    return "$(Read-Host "$Question [t/N]")".Trim() -eq 't'
}

function Update-App {
    # composer.lock przypina wersje – najpierw podnosimy je do najnowszych łatek w ramach composer.json
    & docker run --rm -e COMPOSER_HOME=/tmp/composer -v "${Dir}:/app" -w /app composer:2 update --no-install --no-scripts --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Nie udało się zaktualizować zależności.' }
    Invoke-Compose build --pull --no-cache
    if ($LASTEXITCODE -ne 0) { throw 'Nie udało się zbudować obrazu.' }
    Invoke-Compose up -d
    $script:ExitCode = $LASTEXITCODE
}

function Restore-App([string[]]$Options) {
    if ($Options.Count -lt 1 -or -not (Test-Path -LiteralPath (Resolve-UserPath $Options[0]) -PathType Leaf)) {
        Write-Host 'Użycie: .\worklog.cmd restore PLIK.db'
        $script:ExitCode = 1
        return
    }
    $file = Resolve-UserPath $Options[0]
    if (-not (Confirm-Action "Nadpisać obecne dane kopią ${file}?")) { $script:ExitCode = 1; return }
    $safety = Join-Path (Read-Config).WORKLOG_BACKUP_DIR ('przed-przywroceniem-{0:yyyyMMdd-HHmmss}.db' -f (Get-Date))
    try {
        Backup-Database $safety
    } catch {
        Write-Host "Nie udało się zrobić kopii obecnego stanu – przerywam, nic nie zmieniono.`n$($_.Exception.Message)"
        $script:ExitCode = 1
        return
    }
    Write-Host "Obecny stan zapisano na wszelki wypadek: $safety"
    Restore-Database $file
    Write-Host 'Przywrócono.'
}

# Usuwa wszystko, co założył install.ps1. Kopie zapasowe zostają – to Twoje dane.
function Uninstall-App([string[]]$Options) {
    $backups = (Read-Config).WORKLOG_BACKUP_DIR
    if (-not ($Options | Where-Object { $_ -match '^-{0,2}tak$' })) {
        Write-Host "Zostaną usunięte: kontener i obraz worklog, wolumen $Volume (baza, token Jiry),"
        Write-Host 'agent w tle (przypomnienie, kopie) i skrót „Raport czasu” w menu Start.'
        Write-Host "Przedtem zrobię ostatnią kopię bazy do $backups. Kopie zapasowe i ten katalog zostają."
        if (-not (Confirm-Action 'Odinstalować?')) { $script:ExitCode = 1; return }
    }

    if ((Invoke-Native docker @('volume', 'inspect', $Volume)).Code -eq 0) {
        if (-not (Test-ContainerRunning)) {
            Write-Host 'Kontener nie działa, więc nie zrobię ostatniej kopii. Uruchom go (.\worklog.cmd up) i spróbuj ponownie.'
            $script:ExitCode = 1
            return
        }
        $last = Join-Path $backups ('przed-odinstalowaniem-{0:yyyyMMdd-HHmmss}.db' -f (Get-Date))
        try {
            Backup-Database $last
        } catch {
            Write-Host "Nie udało się zrobić ostatniej kopii – przerywam, nic nie usunięto.`n$($_.Exception.Message)"
            $script:ExitCode = 1
            return
        }
        Write-Host "Ostatnia kopia: $last"
    }

    Remove-Agent
    if ($OnWindows) { Remove-Item -LiteralPath (Get-MenuLinkPath) -ErrorAction SilentlyContinue }
    Remove-Item -LiteralPath (Join-Path $backups '.last-run'), (Join-Path $backups '.agent.log') -Force -ErrorAction SilentlyContinue

    $null = Invoke-Native docker @('compose', '--env-file', $EnvFile, '-f', $ComposeFile, 'down', '--rmi', 'local')
    $null = Invoke-Native docker @('rm', '-f', $Container)
    $null = Invoke-Native docker @('volume', 'rm', $Volume)
    $null = Invoke-Native docker @('image', 'rm', $Image)
    Remove-Item -LiteralPath $EnvFile -Force -ErrorAction SilentlyContinue

    Write-Host "Odinstalowano. Kopie zapasowe zostały w $backups."
    Write-Host 'Pamiętaj o unieważnieniu tokenu w Jirze, jeśli nie będzie już potrzebny.'
    Write-Host "Katalog z kodem ($Dir) możesz teraz usunąć ręcznie."
}

function Show-Help {
    Write-Host @'
.\worklog.cmd up        – zbuduj i uruchom
.\worklog.cmd down      – zatrzymaj i usuń kontener (dane zostają w wolumenie)
.\worklog.cmd restart   – restart
.\worklog.cmd logs      – logi na żywo (Ctrl+C kończy)
.\worklog.cmd status    – stan kontenera
.\worklog.cmd update    – przebuduj obraz na najnowszych łatkach PHP/Symfony
.\worklog.cmd backup    – kopia bazy do %USERPROFILE%\worklog-backup-*.db (albo: backup PLIK)
.\worklog.cmd restore F – przywróć kopię
.\worklog.cmd remind    – co zostało do zrobienia dzisiaj
.\worklog.cmd uninstall – usuń wszystko (najpierw robi ostatnią kopię bazy)
'@
}

function Invoke-Worklog([string[]]$Arguments) {
    $command = 'help'
    $rest = @()
    if ($Arguments.Count -gt 0) { $command = $Arguments[0] }
    if ($Arguments.Count -gt 1) { $rest = $Arguments[1..($Arguments.Count - 1)] }

    switch ($command) {
        'up'      { Invoke-Compose up -d --build; $script:ExitCode = $LASTEXITCODE }
        'down'    { Invoke-Compose down; $script:ExitCode = $LASTEXITCODE }
        'restart' { Invoke-Compose restart; $script:ExitCode = $LASTEXITCODE }
        'logs'    { Invoke-Compose logs -f --tail=100; $script:ExitCode = $LASTEXITCODE }
        'status'  {
            Invoke-Compose ps
            $script:ExitCode = $LASTEXITCODE
            Write-Host ''
            Write-Host ('http://127.0.0.1:{0}' -f (Read-Config).WORKLOG_PORT)
        }
        'update'  { Update-App }
        'backup'  {
            $out = Join-Path $HOME ('worklog-backup-{0:yyyyMMdd-HHmmss}.db' -f (Get-Date))
            if ($rest.Count -gt 0) { $out = Resolve-UserPath $rest[0] }
            Backup-Database $out
            Write-Host "Kopia zapisana: $out"
        }
        'restore'   { Restore-App $rest }
        'uninstall' { Uninstall-App $rest }
        'remind'    { & docker exec $Container php bin/console app:remind -v; $script:ExitCode = $LASTEXITCODE }
        'console'   { & docker exec -it $Container php bin/console @rest; $script:ExitCode = $LASTEXITCODE }
        'agent'     { Start-AgentLoop }
        'notify-test' {
            Show-Notification 'Raport czasu pracy' 'Tak będzie wyglądać przypomnienie. Kliknij, żeby otworzyć raport.' ('http://127.0.0.1:{0}/today' -f (Read-Config).WORKLOG_PORT)
        }
        default { Show-Help }
    }
}

# Uruchomione jako polecenie (nie dołączone przez „. worklog.ps1”)
if ($MyInvocation.InvocationName -ne '.') {
    $script:ExitCode = 0
    $previousEncoding = [Console]::OutputEncoding
    try {
        try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }   # polskie znaki z dockera
        Invoke-Worklog @($args)
    } catch {
        Write-Host $_.Exception.Message -ForegroundColor Red
        $script:ExitCode = 1
    } finally {
        try { [Console]::OutputEncoding = $previousEncoding } catch { }
    }
    exit $script:ExitCode
}
