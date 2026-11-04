<?php

namespace App\Service;

/**
 * Kopia i przywracanie bazy raportu (używa tego polecenie app:db). Kontrakt nie zależy od silnika bazy.
 * Każdy błąd to BackupException z gotowym tekstem dla człowieka.
 */
interface DatabaseBackup
{
    /**
     * Spójna kopia bazy pod ścieżką $target, bez tokenów i sprawdzona pod kątem integralności.
     * Nieudana kopia nie niszczy poprzedniej pod tą samą ścieżką.
     *
     * @throws BackupException
     */
    public function backup(string $target): void;

    /**
     * Przywrócenie bazy z kopii $source. Przy błędzie (także gdy plik nie jest poprawną kopią raportu)
     * nic się nie zmienia.
     *
     * @throws BackupException
     */
    public function restore(string $source): void;

    /**
     * Kopia przed zmianą struktury bazy, składana do katalogu $dir. Zwraca null, gdy kopia nie była potrzebna
     * (nie ma czego chronić albo struktura jest już aktualna). Przy błędzie baza nie zostaje zmieniona.
     *
     * @throws BackupException
     */
    public function backupBeforeSchemaChange(string $dir): ?SchemaBackup;
}
