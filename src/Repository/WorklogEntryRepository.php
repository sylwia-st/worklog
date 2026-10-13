<?php

namespace App\Repository;

use App\Entity\WorklogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WorklogEntry> */
class WorklogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorklogEntry::class);
    }

    /** @return WorklogEntry[] wszystkie wpisy z działającym stoperem (normalnie najwyżej jeden) */
    public function findRunning(): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.day', 'd')->addSelect('d')
            ->andWhere('e.timerStartedAt IS NOT NULL')
            ->getQuery()
            ->getResult();
    }
}
