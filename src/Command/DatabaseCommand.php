<?php

namespace App\Command;

use App\Domain\Template;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Kopia i przywracanie bazy SQLite wewnątrz kontenera – skrypt na hoście tylko przenosi plik (docker cp):
 *  app:db backup PLIK   – spójna kopia (VACUUM INTO, działa także w trakcie zapisu) ze sprawdzeniem integralności,
 *  app:db restore PLIK  – przywrócenie przez backup API SQLite (bezpieczne przy WAL i działającej aplikacji),
 *  app:db schema-backup KATALOG – kopia przed zmianą struktury bazy (start kontenera, przed doctrine:schema:update).
 * Używają tego ./worklog i worklog.ps1; po przywróceniu wywołują jeszcze doctrine:schema:update (kopia sprzed zmian w bazie).
 */
#[AsCommand(name: 'app:db', description: 'Kopia (backup), przywracanie (restore) i kopia przed zmianą struktury bazy (schema-backup)')]
final class DatabaseCommand extends Command
{
    /** ile kopii sprzed zmian struktury zostaje w katalogu */
    public const SCHEMA_BACKUPS_KEPT = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('akcja', InputArgument::REQUIRED, 'backup, restore albo schema-backup')
            ->addArgument('plik', InputArgument::REQUIRED, 'Plik kopii w kontenerze, np. /data/.backup.db (przy schema-backup: katalog)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('plik');
        $error = match ($input->getArgument('akcja')) {
            'backup' => $this->backup($file),
            'restore' => $this->restore($file),
            'schema-backup' => $this->schemaBackup($file, $output),
            default => 'Akcja: backup, restore albo schema-backup.',
        };
        if (null === $error) {
            return Command::SUCCESS;
        }
        // na stderr – skrypt na hoście pokazuje ten tekst w powiadomieniu o nieudanej kopii
        ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($error);

        return Command::FAILURE;
    }

    /** @return string|null błąd albo null, gdy się udało */
    private function backup(string $target): ?string
    {
        try {
            if (is_file($target)) {
                unlink($target);
            }
            $this->open($this->databasePath())->exec("VACUUM INTO '".\SQLite3::escapeString($target)."'");
            $this->removeToken($target);
            $check = $this->open($target, \SQLITE3_OPEN_READONLY)->querySingle('PRAGMA integrity_check');
        } catch (\Exception $e) {
            return 'Kopia się nie udała: '.$e->getMessage();
        }

        return 'ok' === $check ? null : 'Kopia uszkodzona: '.$check;
    }

    /** @return string|null błąd albo null, gdy się udało */
    private function restore(string $source): ?string
    {
        try {
            $src = $this->open($source, \SQLITE3_OPEN_READONLY);
            $check = $src->querySingle('PRAGMA integrity_check');
        } catch (\Exception $e) {
            return 'Plik kopii jest uszkodzony – nic nie zmieniono. ('.$e->getMessage().')';
        }
        if ('ok' !== $check) {
            return 'Plik kopii jest uszkodzony – nic nie zmieniono.';
        }
        // inny plik SQLite (np. pomyłka przy wyborze) wyczyściłby wszystkie dane
        if (null === $src->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'work_day'")) {
            return 'To nie jest kopia raportu czasu (brak tabeli work_day) – nic nie zmieniono.';
        }
        try {
            $restored = $src->backup($this->open($this->databasePath()));
        } catch (\Exception) {
            $restored = false;
        }

        return $restored ? null : 'Przywracanie nie powiodło się – nic nie zmieniono.';
    }

    /**
     * Doctrine synchronizuje schemat w pełni – kolumna usunięta albo przemianowana w encjach znika razem z danymi.
     * Dlatego gdy start kontenera ma coś zmienić w istniejącej bazie, najpierw kopia do $dir (zostaje 10 ostatnich).
     *
     * @return string|null błąd albo null, gdy się udało (także gdy kopia nie była potrzebna)
     */
    private function schemaBackup(string $dir, OutputInterface $output): ?string
    {
        try {
            $path = $this->databasePath();
            // pierwsze uruchomienie – nie ma czego chronić
            if (!is_file($path) || null === $this->open($path, \SQLITE3_OPEN_READONLY)
                    ->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'work_day'")) {
                return null;
            }
            $sql = (new SchemaTool($this->em))->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata());
        } catch (\Exception $e) {
            return 'Nie udało się sprawdzić struktury bazy: '.$e->getMessage();
        }
        if ([] === $sql) {
            return null;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return "Nie udało się utworzyć katalogu $dir – baza nie została zmieniona.";
        }
        $file = sprintf('%s/przed-zmiana-bazy-%s.db', rtrim($dir, '/'), date('Ymd-His'));
        if (null !== $error = $this->backup($file)) {
            return $error.' – baza nie została zmieniona.';
        }
        $old = glob(rtrim($dir, '/').'/przed-zmiana-bazy-*.db') ?: [];
        rsort($old);
        foreach (array_slice($old, self::SCHEMA_BACKUPS_KEPT) as $f) {
            unlink($f);
        }
        $output->writeln(sprintf('Zmiana struktury bazy (%s) – najpierw kopia: %s',
            Template::plural(count($sql), 'polecenie SQL', 'polecenia SQL', 'poleceń SQL'), $file));

        return null;
    }

    /**
     * Token Jiry nie trafia do kopii. Zwykle i tak go w bazie nie ma (leży w pliku obok – Settings::loadToken),
     * ale po przywróceniu starej kopii zostaje w bazie, dopóki aplikacja go nie przeniesie.
     * secure_delete nadpisuje zerami miejsce po starej wartości, więc nie da się jej odczytać z pliku.
     */
    private function removeToken(string $file): void
    {
        $db = $this->open($file);
        if (null !== $db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'setting'")) {
            $db->exec('PRAGMA secure_delete = ON');
            $db->exec("UPDATE setting SET value = '' WHERE name = 'jira_token'");
        }
        $db->close();
    }

    private function open(string $path, int $flags = \SQLITE3_OPEN_READWRITE | \SQLITE3_OPEN_CREATE): \SQLite3
    {
        $db = new \SQLite3($path, $flags);
        $db->enableExceptions(true);
        $db->busyTimeout(10000);

        return $db;
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
