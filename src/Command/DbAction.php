<?php

namespace App\Command;

/** Akcje polecenia app:db – wartości są częścią umowy z ./worklog, worklog.ps1 i docker/entrypoint.sh. */
enum DbAction: string
{
    case Backup = 'backup';
    case Restore = 'restore';
    case SchemaBackup = 'schema-backup';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
