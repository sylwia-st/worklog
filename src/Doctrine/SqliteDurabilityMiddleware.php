<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Ustawienia SQLite odporne na nagłe wyłączenie komputera:
 *  - WAL: zapis nie nadpisuje bazy w miejscu, więc przerwany zapis nie psuje wcześniejszych danych,
 *  - synchronous=FULL: każdy zapis jest fizycznie na dysku, zanim aplikacja potwierdzi „zapisano”,
 *  - busy_timeout: dwa równoczesne zapisy (np. autozapis i stoper) czekają na siebie zamiast się wykładać.
 */
#[AsMiddleware]
final class SqliteDurabilityMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);
                if (isset($params['path']) || str_contains((string) ($params['driver'] ?? ''), 'sqlite')) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                    $connection->exec('PRAGMA synchronous = FULL');
                    $connection->exec('PRAGMA busy_timeout = 5000');
                }

                return $connection;
            }
        };
    }
}
