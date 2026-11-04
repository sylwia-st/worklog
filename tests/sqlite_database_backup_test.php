<?php
// Testy serwisu SqliteDatabaseBackup (backup, restore, kopia przed zmianą struktury): php tests/sqlite_database_backup_test.php
// Wymaga rozszerzenia sqlite3 i vendor/ (doctrine/dbal). Pliki SQLite powstają w katalogu tymczasowym,
// lista zmian schematu pochodzi z fałszywego SchemaChanges (bez metadanych encji).

require __DIR__.'/../vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $file = __DIR__.'/../src/'.str_replace(['App\\', '\\'], ['', '/'], $class).'.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Domain\Template;
use App\Service\BackupException;
use App\Service\SqliteDatabaseBackup;
use App\Service\SchemaChanges;
use Doctrine\DBAL\DriverManager;

$fail = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $fail;
    if ($actual !== $expected) {
        ++$fail;
        echo "FAIL $name: oczekiwano ".var_export($expected, true).', jest '.var_export($actual, true)."\n";
    }
}

function rrmdir(string $dir): void
{
    foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) ? rrmdir($f) : unlink($f);
    }
    @rmdir($dir);
}

function sqlite(string $path, string $sql = ''): void
{
    $db = new SQLite3($path);
    if ('' !== $sql) {
        $db->exec($sql);
    }
    $db->close();
}

function scalar(string $path, string $sql): mixed
{
    $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $v = $db->querySingle($sql);
    $db->close();

    return $v;
}

/** Serwis z fałszywą listą zmian schematu. */
final class Harness
{
    public SqliteDatabaseBackup $service;
    public object $changes;

    public function __construct(string $dbPath)
    {
        $this->changes = new class implements SchemaChanges {
            /** @var list<string> */
            public array $sql = [];

            public function pendingSql(): array
            {
                return $this->sql;
            }
        };
        $this->service = new SqliteDatabaseBackup(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]), $this->changes);
    }
}

/**
 * Woła serwis tak jak komenda i zwraca [kod, stdout, stderr] – dzięki temu scenariusze porównują te same teksty.
 *
 * @return array{0: int, 1: string, 2: string}
 */
function run(Harness $h, array $args): array
{
    try {
        $out = '';
        switch ($args['akcja']) {
            case 'backup':
                $h->service->backup($args['plik']);
                break;
            case 'restore':
                $h->service->restore($args['plik']);
                break;
            case 'schema-backup':
                $r = $h->service->backupBeforeSchemaChange($args['plik']);
                if (null !== $r) {
                    $out = sprintf('Zmiana struktury bazy (%s) – najpierw kopia: %s',
                        Template::plural($r->statements, 'polecenie SQL', 'polecenia SQL', 'poleceń SQL'), $r->file);
                }
                break;
        }
    } catch (BackupException $e) {
        return [1, '', $e->getMessage()];
    }

    return [0, $out, ''];
}

function command(string $dbPath): Harness
{
    return new Harness($dbPath);
}

const WORKLOG_SQL = "CREATE TABLE work_day (id INTEGER PRIMARY KEY, day TEXT); INSERT INTO work_day (day) VALUES ('2026-01-01');
    CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT); INSERT INTO setting VALUES ('jira_token', 'SEKRET-J'), ('toggl_token', 'SEKRET-T'), ('theme', 'dark');";

$tmp = sys_get_temp_dir().'/worklog-dbbackup-'.bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(static fn () => rrmdir($tmp));
$dbFile = "$tmp/worklog.db";

// --- backup ---
sqlite($dbFile, WORKLOG_SQL);
$before = md5_file($dbFile);
$cmd = command($dbFile);
$target = "$tmp/kopia.db";
[$code, , $err] = run($cmd, ['akcja' => 'backup', 'plik' => $target]);
check('backup: kod 0', [$code, $err], [0, '']);
check('backup: integrity_check kopii', scalar($target, 'PRAGMA integrity_check'), 'ok');
check('backup: dane w kopii', scalar($target, 'SELECT count(*) FROM work_day'), 1);
check('backup: tokeny wyczyszczone', scalar($target, "SELECT group_concat(value, '|') FROM setting WHERE name IN ('jira_token','toggl_token')"), '|');
check('backup: inne ustawienia zostają', scalar($target, "SELECT value FROM setting WHERE name = 'theme'"), 'dark');
check('backup: tokenów nie ma w bajtach pliku', str_contains((string) file_get_contents($target), 'SEKRET'), false);
check('backup: źródło nietknięte', md5_file($dbFile), $before);
check('backup: źródło nadal ma tokeny', scalar($dbFile, "SELECT value FROM setting WHERE name = 'jira_token'"), 'SEKRET-J');
check('backup: brak plików tymczasowych', glob("$tmp/.*.tmp"), []);

// ponowny backup pod tę samą ścieżkę podmienia kopię
sqlite($dbFile, "INSERT INTO work_day (day) VALUES ('2026-01-02')");
[$code] = run($cmd, ['akcja' => 'backup', 'plik' => $target]);
check('backup: nadpisanie istniejącej kopii', [$code, scalar($target, 'SELECT count(*) FROM work_day')], [0, 2]);

// --- backup: nieudana kopia nie rusza poprzedniej ---
$good = md5_file($target);
[$code, , $err] = run($cmd, ['akcja' => 'backup', 'plik' => "$tmp/nie-ma-katalogu/kopia.db"]);
check('backup: nieistniejący katalog celu – błąd', [$code, str_starts_with($err, 'Kopia się nie udała: ')], [1, true]);
// cel w katalogu tylko do odczytu pominięty, gdy test działa jako root – zamiast tego psujemy źródło
$broken = "$tmp/zepsuta.db";
file_put_contents($broken, str_repeat('to nie jest sqlite ', 500));
[$code, , $err] = run(command($broken), ['akcja' => 'backup', 'plik' => $target]);
check('backup: uszkodzone źródło – błąd', [$code, str_starts_with($err, 'Kopia się nie udała: ')], [1, true]);
check('backup: poprzednia kopia przetrwała błąd', md5_file($target), $good);
check('backup: po błędzie brak plików tymczasowych', glob("$tmp/.*.tmp"), []);

// --- backup: brak bazy ---
$missing = "$tmp/brak.db";
[$code, , $err] = run(command($missing), ['akcja' => 'backup', 'plik' => "$tmp/z-braku.db"]);
check('backup: brak bazy – kod 1 i komunikat', [$code, $err], [1, 'Kopia się nie udała: brak pliku bazy.']);
check('backup: brak bazy – nie tworzy pustej bazy', file_exists($missing), false);
check('backup: brak bazy – nie tworzy kopii', file_exists("$tmp/z-braku.db"), false);
check('backup: brak bazy – brak plików tymczasowych', glob("$tmp/.*.tmp"), []);

// --- restore ---
$notSqlite = "$tmp/tekst.db";
file_put_contents($notSqlite, str_repeat('zwykły tekst, nie baza ', 300));
$dstBefore = md5_file($dbFile);
[$code, , $err] = run($cmd, ['akcja' => 'restore', 'plik' => $notSqlite]);
check('restore: plik nie-SQLite', [$code, str_starts_with($err, 'Plik kopii jest uszkodzony – nic nie zmieniono.')], [1, true]);
check('restore: plik nie-SQLite – baza bez zmian', md5_file($dbFile), $dstBefore);

[$code, , $err] = run($cmd, ['akcja' => 'restore', 'plik' => "$tmp/nie-ma.db"]);
check('restore: brak pliku kopii', [$code, str_starts_with($err, 'Plik kopii jest uszkodzony – nic nie zmieniono.')], [1, true]);
check('restore: brak pliku kopii – nie tworzy go', file_exists("$tmp/nie-ma.db"), false);

$corrupt = "$tmp/uszkodzona.db";
copy($target, $corrupt);
$h = fopen($corrupt, 'r+b');
fseek($h, 4096);
fwrite($h, str_repeat("\xFF", 2048));
fclose($h);
[$code, , $err] = run($cmd, ['akcja' => 'restore', 'plik' => $corrupt]);
check('restore: uszkodzona kopia SQLite', [$code, str_starts_with($err, 'Plik kopii jest uszkodzony – nic nie zmieniono.')], [1, true]);
check('restore: uszkodzona kopia – baza bez zmian', md5_file($dbFile), $dstBefore);

$foreign = "$tmp/obca.db";
sqlite($foreign, 'CREATE TABLE cos (a INTEGER); INSERT INTO cos VALUES (1);');
[$code, , $err] = run($cmd, ['akcja' => 'restore', 'plik' => $foreign]);
check('restore: obcy SQLite bez work_day', [$code, $err], [1, 'To nie jest kopia raportu czasu (brak tabeli work_day) – nic nie zmieniono.']);
check('restore: obcy SQLite – baza bez zmian', md5_file($dbFile), $dstBefore);

// poprawny restore podmienia dane
$older = "$tmp/starsza.db";
sqlite($older, "CREATE TABLE work_day (id INTEGER PRIMARY KEY, day TEXT); INSERT INTO work_day (day) VALUES ('2025-05-05'), ('2025-05-06'), ('2025-05-07');");
[$code, , $err] = run($cmd, ['akcja' => 'restore', 'plik' => $older]);
check('restore: poprawny – kod 0', [$code, $err], [0, '']);
check('restore: poprawny – dane podmienione', scalar($dbFile, "SELECT group_concat(day, ',') FROM work_day"), '2025-05-05,2025-05-06,2025-05-07');
check('restore: poprawny – stare tabele zniknęły', scalar($dbFile, "SELECT count(*) FROM sqlite_master WHERE name = 'setting'"), 0);

// --- schema-backup ---
$sbDir = "$tmp/kopie";
$sbDb = "$tmp/schemat.db";
$sc = command($sbDb);
$sc->changes->sql = ['ALTER TABLE x ADD y INTEGER'];

[$code, $out, $err] = run($sc, ['akcja' => 'schema-backup', 'plik' => $sbDir]);
check('schema-backup: brak bazy – nic nie robi', [$code, $out, $err, file_exists($sbDir), file_exists($sbDb)], [0, '', '', false, false]);

sqlite($sbDb, 'CREATE TABLE inna (a INTEGER);');
[$code, $out, $err] = run($sc, ['akcja' => 'schema-backup', 'plik' => $sbDir]);
check('schema-backup: baza bez work_day – nic nie robi', [$code, $out, $err, file_exists($sbDir)], [0, '', '', false]);

sqlite($sbDb, WORKLOG_SQL);
$sc->changes->sql = [];
[$code, $out, $err] = run($sc, ['akcja' => 'schema-backup', 'plik' => $sbDir]);
check('schema-backup: schemat zgodny – nic nie robi', [$code, $out, $err, file_exists($sbDir)], [0, '', '', false]);

$sc->changes->sql = ['ALTER TABLE a ADD b INTEGER', 'DROP TABLE c'];
[$code, $out, $err] = run($sc, ['akcja' => 'schema-backup', 'plik' => $sbDir.'/']);
$files = glob("$sbDir/*");
check('schema-backup: kod 0 i jedna kopia', [$code, $err, count($files)], [0, '', 1]);
check('schema-backup: nazwa kopii', preg_match('~/przed-zmiana-bazy-\d{8}-\d{6}\.db$~', $files[0] ?? ''), 1);
check('schema-backup: komunikat', $out, 'Zmiana struktury bazy (2 polecenia SQL) – najpierw kopia: '.($files[0] ?? '?'));
check('schema-backup: kopia ma dane i bez tokenów', [scalar($files[0], 'SELECT count(*) FROM work_day'), scalar($files[0], "SELECT value FROM setting WHERE name = 'jira_token'")], [1, '']);

// dwie kopie w tej samej sekundzie nie nadpisują się: wstępnie zajmujemy nazwy bieżącej sekundy
$sc->changes->sql = ['ALTER TABLE a ADD b INTEGER'];
$same = [];
for ($i = 0; $i < 3; ++$i) {
    [$code, $out] = run($sc, ['akcja' => 'schema-backup', 'plik' => $sbDir]);
    preg_match('~kopia: (\S+)$~', $out, $m);
    $same[] = $m[1] ?? '?';
}
check('schema-backup: kolejne kopie mają różne nazwy', count(array_unique($same)), 3);
check('schema-backup: wszystkie kopie istnieją', array_sum(array_map('is_file', $same)), 3);

// rotacja: 15 starych kopii + nowa → zostaje 10 najnowszych (sufiksy -N liczone naturalnie, -10 nowsze niż -9)
rrmdir($sbDir);
mkdir($sbDir);
$old = date('Ymd', strtotime('-1 year'));
$names = [];
for ($i = 1; $i <= 12; ++$i) {
    $names[] = sprintf('przed-zmiana-bazy-%s-%06d.db', $old, $i * 100);
}
$names[] = "przed-zmiana-bazy-$old-235959.db";
$names[] = "przed-zmiana-bazy-$old-235959-2.db";
$names[] = "przed-zmiana-bazy-$old-235959-10.db";
foreach ($names as $n) {
    touch("$sbDir/$n");
}
touch("$sbDir/inny-plik.db");
[$code, $out] = run($sc, ['akcja' => 'schema-backup', 'plik' => $sbDir]);
$left = array_map('basename', glob("$sbDir/przed-zmiana-bazy-*.db"));
check('rotacja: zostaje 10 kopii', [$code, count($left)], [0, SqliteDatabaseBackup::SCHEMA_BACKUPS_KEPT]);
check('rotacja: obca nazwa nietknięta', is_file("$sbDir/inny-plik.db"), true);
check('rotacja: najnowsze zostają (w tym sufiksowane)', [
    in_array("przed-zmiana-bazy-$old-235959-10.db", $left, true),
    in_array("przed-zmiana-bazy-$old-235959-2.db", $left, true),
    in_array("przed-zmiana-bazy-$old-235959.db", $left, true),
    in_array(basename(preg_replace('~^.*kopia: ~', '', $out)), $left, true),
], [true, true, true, true]);
check('rotacja: najstarsze usunięte', is_file("$sbDir/przed-zmiana-bazy-$old-000100.db"), false);
check('rotacja: po schema-backup brak plików tymczasowych', glob("$sbDir/.*.tmp"), []);

echo $fail ? "\n$fail błędów\n" : "OK – wszystkie testy przeszły\n";
exit($fail ? 1 : 0);
