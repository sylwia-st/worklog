<?php
// Testy polecenia app:db (cienka warstwa nad DatabaseBackup): php tests/database_command_test.php
// Wymaga rozszerzenia sqlite3 i vendor/ (symfony/console, doctrine/dbal). Scenariusze serwisu: tests/sqlite_database_backup_test.php.

require __DIR__.'/../vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $file = __DIR__.'/../src/'.str_replace(['App\\', '\\'], ['', '/'], $class).'.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Command\DatabaseCommand;
use App\Service\BackupException;
use App\Service\DatabaseBackup;
use App\Service\SchemaBackup;
use App\Service\SqliteDatabaseBackup;
use App\Service\SchemaChanges;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Tester\CommandTester;

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

/** @return array{0: int, 1: string, 2: string} kod, stdout, stderr */
function run(string $dbPath, array $sql, array $args): array
{
    $changes = new class($sql) implements SchemaChanges {
        /** @param list<string> $sql */
        public function __construct(private array $sql)
        {
        }

        public function pendingSql(): array
        {
            return $this->sql;
        }
    };
    $service = new SqliteDatabaseBackup(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]), $changes);
    $t = new CommandTester(new DatabaseCommand($service));
    $code = $t->execute($args, ['capture_stderr_separately' => true]);

    return [$code, trim($t->getDisplay()), trim($t->getErrorOutput())];
}

$tmp = sys_get_temp_dir().'/worklog-dbcmd-'.bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(static fn () => rrmdir($tmp));
$dbFile = "$tmp/worklog.db";
$db = new SQLite3($dbFile);
$db->exec("CREATE TABLE work_day (id INTEGER PRIMARY KEY, day TEXT); INSERT INTO work_day (day) VALUES ('2026-01-01');");
$db->close();

[$code, $out, $err] = run($dbFile, [], ['akcja' => 'skasuj', 'plik' => "$tmp/x"]);
check('nieznana akcja', [$code, $out, $err], [1, '', 'Akcja: backup, restore albo schema-backup.']);

[$code, $out, $err] = run($dbFile, [], ['akcja' => 'backup', 'plik' => "$tmp/kopia.db"]);
check('backup: kod 0, cisza', [$code, $out, $err, is_file("$tmp/kopia.db")], [0, '', '', true]);

[$code, $out, $err] = run("$tmp/brak.db", [], ['akcja' => 'backup', 'plik' => "$tmp/z-braku.db"]);
check('backup bez bazy: stderr i kod 1', [$code, $out, $err], [1, '', 'Kopia się nie udała: brak pliku bazy.']);

[$code, $out, $err] = run($dbFile, [], ['akcja' => 'restore', 'plik' => "$tmp/nie-ma.db"]);
check('restore: błąd na stderr', [$code, $out, str_starts_with($err, 'Plik kopii jest uszkodzony – nic nie zmieniono.')], [1, '', true]);

[$code, $out, $err] = run($dbFile, ['ALTER TABLE a ADD b INTEGER'], ['akcja' => 'schema-backup', 'plik' => "$tmp/kopie"]);
$files = glob("$tmp/kopie/*");
check('schema-backup: komunikat na stdout', [$code, $err, $out], [0, '', 'Zmiana struktury bazy (1 polecenie SQL) – najpierw kopia: '.($files[0] ?? '?')]);

[$code, $out, $err] = run($dbFile, [], ['akcja' => 'schema-backup', 'plik' => "$tmp/kopie2"]);
check('schema-backup: zgodny schemat – cisza', [$code, $out, $err, file_exists("$tmp/kopie2")], [0, '', '', false]);

// komenda zależy tylko od interfejsu: fałszywa implementacja rzuca BackupException
$failing = new class implements DatabaseBackup {
    public function backup(string $target): void
    {
        throw new BackupException('Fałszywa kopia nie powiodła się.');
    }

    public function restore(string $source): void
    {
        throw new BackupException('Fałszywe przywracanie nie powiodło się.');
    }

    public function backupBeforeSchemaChange(string $dir): ?SchemaBackup
    {
        throw new BackupException('Fałszywa kopia przed zmianą nie powiodła się.');
    }
};
$t = new CommandTester(new DatabaseCommand($failing));
$code = $t->execute(['akcja' => 'backup', 'plik' => "$tmp/fake.db"], ['capture_stderr_separately' => true]);
check('inna implementacja: BackupException na stderr, kod 1', [$code, trim($t->getDisplay()), trim($t->getErrorOutput())], [1, '', 'Fałszywa kopia nie powiodła się.']);

echo $fail ? "\n$fail błędów\n" : "OK – wszystkie testy przeszły\n";
exit($fail ? 1 : 0);
