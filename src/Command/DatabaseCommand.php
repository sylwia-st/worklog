<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Kopia i przywracanie bazy SQLite wewnątrz kontenera – skrypt na hoście tylko przenosi plik (docker cp):
 *  app:db backup PLIK   – spójna kopia (VACUUM INTO, działa także w trakcie zapisu) ze sprawdzeniem integralności,
 *  app:db restore PLIK  – przywrócenie przez backup API SQLite (bezpieczne przy WAL i działającej aplikacji).
 * Używa tego worklog.ps1 (Windows); ./worklog na Linuksie robi to samo kodem PHP podanym w wierszu poleceń.
 */
#[AsCommand(name: 'app:db', description: 'Kopia (backup) i przywracanie (restore) bazy z pliku w kontenerze')]
final class DatabaseCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('akcja', InputArgument::REQUIRED, 'backup albo restore')
            ->addArgument('plik', InputArgument::REQUIRED, 'Plik kopii w kontenerze, np. /data/.backup.db');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('plik');
        $error = match ($input->getArgument('akcja')) {
            'backup' => $this->backup($file),
            'restore' => $this->restore($file),
            default => 'Akcja: backup albo restore.',
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
        try {
            $restored = $src->backup($this->open($this->databasePath()));
        } catch (\Exception) {
            $restored = false;
        }

        return $restored ? null : 'Przywracanie nie powiodło się – nic nie zmieniono.';
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
