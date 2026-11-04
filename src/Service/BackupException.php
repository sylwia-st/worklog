<?php

namespace App\Service;

/** Błąd kopii albo przywracania bazy; app:db pokazuje go na stderr (host wyświetla ten tekst w powiadomieniu). */
final class BackupException extends \RuntimeException
{
}
