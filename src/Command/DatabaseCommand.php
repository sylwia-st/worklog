<?php

namespace App\Command;

use App\Domain\Template;
use App\Service\BackupException;
use App\Service\DatabaseBackup;
use App\Service\SchemaBackup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Kopia i przywracanie bazy wewnątrz kontenera – skrypt na hoście tylko przenosi plik (docker cp):
 *  app:db backup PLIK           – spójna kopia bez tokenów, ze sprawdzeniem integralności,
 *  app:db restore PLIK          – przywrócenie bazy z kopii,
 *  app:db schema-backup KATALOG – kopia przed zmianą struktury bazy (start kontenera, przed doctrine:schema:update).
 * Cała logika jest w DatabaseBackup (interfejs; dziś jedyna implementacja to SqliteDatabaseBackup). Używają tego ./worklog i worklog.ps1; po przywróceniu wywołują jeszcze
 * doctrine:schema:update (kopia sprzed zmian w bazie).
 * Błędy to BackupException – execute() wypisuje je na stderr i zwraca kod 1.
 */
#[AsCommand(name: 'app:db', description: 'Kopia (backup), przywracanie (restore) i kopia przed zmianą struktury bazy (schema-backup)')]
final class DatabaseCommand extends Command
{
    public function __construct(private readonly DatabaseBackup $backup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('akcja', InputArgument::REQUIRED, 'backup, restore albo schema-backup', null, DbAction::values())
            ->addArgument('plik', InputArgument::REQUIRED, 'Plik kopii w kontenerze, np. /data/.backup.db (przy schema-backup: katalog)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('plik');
        try {
            match (DbAction::tryFrom((string) $input->getArgument('akcja'))) {
                DbAction::Backup => $this->backup->backup($file),
                DbAction::Restore => $this->backup->restore($file),
                DbAction::SchemaBackup => $this->printSchemaBackup($this->backup->backupBeforeSchemaChange($file), $output),
                null => throw new BackupException('Akcja: backup, restore albo schema-backup.'),
            };
        } catch (BackupException $e) {
            // na stderr – skrypt na hoście pokazuje ten tekst w powiadomieniu o nieudanej kopii
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function printSchemaBackup(?SchemaBackup $result, OutputInterface $output): void
    {
        if (null !== $result) {
            $output->writeln(sprintf('Zmiana struktury bazy (%s) – najpierw kopia: %s',
                Template::plural($result->statements, 'polecenie SQL', 'polecenia SQL', 'poleceń SQL'), $result->file));
        }
    }
}
