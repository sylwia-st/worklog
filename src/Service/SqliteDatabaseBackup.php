<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Implementacja DatabaseBackup dla bazy SQLite (używa jej polecenie app:db):
 *  backup  – spójna kopia (VACUUM INTO do pliku tymczasowego, działa także w trakcie zapisu), bez tokenów,
 *            ze sprawdzeniem integralności; dopiero gotowa i sprawdzona kopia zastępuje plik docelowy,
 *  restore – przywrócenie przez backup API SQLite (bezpieczne przy WAL i działającej aplikacji),
 *  backupBeforeSchemaChange – kopia przed zmianą struktury bazy (start kontenera, przed doctrine:schema:update).
 * Błędy to BackupException z gotowym tekstem dla użytkownika.
 */
final class SqliteDatabaseBackup implements DatabaseBackup
{
    /** ile kopii sprzed zmian struktury zostaje w katalogu */
    public const SCHEMA_BACKUPS_KEPT = 10;

    /** początek nazwy kopii sprzed zmiany struktury (po nim data, opcjonalny sufiks i .db) */
    public const SCHEMA_BACKUP_PREFIX = 'przed-zmiana-bazy-';

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaChanges $schemaChanges,
    ) {
    }

    /**
     * Kopia idzie najpierw do pliku tymczasowego obok celu: nieudana kopia nie kasuje poprzedniej, a plik
     * z tokenami albo uszkodzony nigdy nie zostaje pod docelową nazwą (rename w obrębie katalogu jest atomowy).
     */
    public function backup(string $target): void
    {
        $this->copyTo($target);
    }

    /** Przywrócenie przez backup API SQLite, po sprawdzeniu integrity_check i tabeli work_day w pliku źródłowym. */
    public function restore(string $source): void
    {
        $this->assertWorklogCopy($source);
        try {
            $restored = $this->withDb($source, \SQLITE3_OPEN_READONLY,
                fn (\SQLite3 $src): bool => $this->withDb($this->databasePath(), \SQLITE3_OPEN_READWRITE | \SQLITE3_OPEN_CREATE,
                    static fn (\SQLite3 $dst): bool => $src->backup($dst)));
        } catch (\Exception) {
            $restored = false;
        }
        if (!$restored) {
            throw new BackupException('Przywracanie nie powiodło się – nic nie zmieniono.');
        }
    }

    /**
     * Doctrine synchronizuje schemat w pełni – kolumna usunięta albo przemianowana w encjach znika razem z danymi.
     * Dlatego gdy start kontenera ma coś zmienić w istniejącej bazie, najpierw kopia do $dir (zostaje 10 ostatnich).
     * Zwraca null, gdy bazy jeszcze nie ma, nie ma w niej tabel raportu albo schemat jest już aktualny.
     * Nazwa pliku: SCHEMA_BACKUP_PREFIX + data (+ sufiks -2, -3…).
     */
    public function backupBeforeSchemaChange(string $dir): ?SchemaBackup
    {
        try {
            $path = $this->databasePath();
            // pierwsze uruchomienie – nie ma czego chronić
            if (!is_file($path) || !$this->withDb($path, \SQLITE3_OPEN_READONLY, fn (\SQLite3 $db): bool => $this->hasTable($db, 'work_day'))) {
                return null;
            }
            $sql = $this->schemaChanges->pendingSql();
        } catch (\Exception $e) {
            throw new BackupException('Nie udało się sprawdzić struktury bazy: '.$e->getMessage(), 0, $e);
        }
        if ([] === $sql) {
            return null;
        }

        $suffix = ' – baza nie została zmieniona.';
        $dir = rtrim($dir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new BackupException("Nie udało się utworzyć katalogu $dir$suffix");
        }
        // dwie kopie w tej samej sekundzie nie mogą się nadpisać – kolejna dostaje sufiks -2, -3...
        $base = $dir.'/'.self::SCHEMA_BACKUP_PREFIX.date('Ymd-His');
        $file = $base.'.db';
        for ($n = 2; file_exists($file); ++$n) {
            $file = "$base-$n.db";
        }
        $this->copyTo($file, $suffix);

        $old = glob($dir.'/'.self::SCHEMA_BACKUP_PREFIX.'*.db') ?: [];
        // porządek naturalny: "…-120000-2" jest nowsze od "…-120000" i "-10" od "-9" (rsort po nazwie by tego nie dał)
        usort($old, static fn (string $a, string $b): int => strnatcmp(basename($b, '.db'), basename($a, '.db')));
        foreach (array_slice($old, self::SCHEMA_BACKUPS_KEPT) as $f) {
            @unlink($f);
        }

        return new SchemaBackup($file, count($sql));
    }

    /**
     * Wspólna ścieżka kopii. $suffix dopisuje się do każdego błędu (schema-backup dodaje „baza nie została zmieniona”).
     */
    private function copyTo(string $target, string $suffix = ''): void
    {
        $tmp = sprintf('%s/.%s.%s.tmp', \dirname($target), basename($target), bin2hex(random_bytes(4)));
        try {
            $path = $this->databasePath();
            // READONLY nie utworzy pustej bazy, gdy pliku nie ma – to byłby "udany" backup niczego
            if (!is_file($path)) {
                throw new BackupException('Kopia się nie udała: brak pliku bazy.');
            }
            $this->withDb($path, \SQLITE3_OPEN_READONLY, static fn (\SQLite3 $src): bool => $src->exec("VACUUM INTO '".\SQLite3::escapeString($tmp)."'"));
            $this->removeToken($tmp);
            $check = $this->integrityCheck($tmp);
            if ('ok' !== $check) {
                throw new BackupException('Kopia uszkodzona: '.$check);
            }
            if (!rename($tmp, $target)) {
                throw new \RuntimeException('nie udało się zapisać pliku kopii');
            }
        } catch (\Throwable $e) {
            // plik tymczasowy nigdy nie zostaje po błędzie; po udanym rename już go nie ma
            @unlink($tmp);
            $message = $e instanceof BackupException ? $e->getMessage() : 'Kopia się nie udała: '.$e->getMessage();
            throw new BackupException($message.$suffix, 0, $e);
        }
    }

    /**
     * Inny plik SQLite (np. pomyłka przy wyborze) wyczyściłby wszystkie dane, więc kopia musi przejść
     * integrity_check i zawierać tabelę raportu.
     */
    private function assertWorklogCopy(string $source): void
    {
        try {
            [$check, $isWorklog] = $this->withDb($source, \SQLITE3_OPEN_READONLY, function (\SQLite3 $db): array {
                $check = $db->querySingle('PRAGMA integrity_check');

                return [$check, 'ok' === $check && $this->hasTable($db, 'work_day')];
            });
        } catch (\Exception $e) {
            throw new BackupException('Plik kopii jest uszkodzony – nic nie zmieniono. ('.$e->getMessage().')', 0, $e);
        }
        if ('ok' !== $check) {
            throw new BackupException('Plik kopii jest uszkodzony – nic nie zmieniono.');
        }
        if (!$isWorklog) {
            throw new BackupException('To nie jest kopia raportu czasu (brak tabeli work_day) – nic nie zmieniono.');
        }
    }

    /**
     * Tokeny (Jiry, Toggla) nie trafiają do kopii. Zwykle i tak ich w bazie nie ma (leżą w plikach obok – Settings::loadToken),
     * ale po przywróceniu starej kopii zostaje w bazie, dopóki aplikacja go nie przeniesie.
     * secure_delete nadpisuje zerami miejsce po starej wartości, więc nie da się jej odczytać z pliku.
     */
    private function removeToken(string $file): void
    {
        $this->withDb($file, \SQLITE3_OPEN_READWRITE | \SQLITE3_OPEN_CREATE, function (\SQLite3 $db): void {
            if ($this->hasTable($db, 'setting')) {
                $db->exec('PRAGMA secure_delete = ON');
                $db->exec("UPDATE setting SET value = '' WHERE name IN ('jira_token', 'toggl_token')");
            }
        });
    }

    private function integrityCheck(string $file): string
    {
        return $this->withDb($file, \SQLITE3_OPEN_READONLY, static fn (\SQLite3 $db): string => (string) $db->querySingle('PRAGMA integrity_check'));
    }

    private function hasTable(\SQLite3 $db, string $name): bool
    {
        return null !== $db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '".\SQLite3::escapeString($name)."'");
    }

    /** Otwiera bazę, wykonuje $fn i zawsze zamyka połączenie – wywołujący nie pilnuje close(). */
    private function withDb(string $path, int $flags, \Closure $fn): mixed
    {
        $db = new \SQLite3($path, $flags);
        try {
            $db->enableExceptions(true);
            $db->busyTimeout(10000);

            return $fn($db);
        } finally {
            $db->close();
        }
    }

    private function databasePath(): string
    {
        $path = $this->connection->getParams()['path'] ?? null;
        if (!is_string($path) || '' === $path) {
            throw new \RuntimeException('Baza nie jest plikiem SQLite.');
        }

        return $path;
    }
}
