<?php

namespace App\Service;

/** Kopia zrobiona przed zmianą struktury bazy: plik oraz liczba poleceń SQL, które ją wywołały. */
final class SchemaBackup
{
    public function __construct(
        public readonly string $file,
        public readonly int $statements,
    ) {
    }
}
