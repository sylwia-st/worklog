<?php

namespace App\Service;

/** Zmiany struktury bazy, które czekają na wykonanie – szew, żeby DatabaseBackup dało się testować bez metadanych encji. */
interface SchemaChanges
{
    /**
     * Polecenia SQL, które wykonałby doctrine:schema:update.
     *
     * @return list<string>
     */
    public function pendingSql(): array;
}
