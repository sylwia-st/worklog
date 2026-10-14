<?php

namespace App\Repository;

use App\Entity\PlannedChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PlannedChange> */
class PlannedChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlannedChange::class);
    }

    /** @return PlannedChange[] */
    public function findFrom(\DateTimeImmutable $from, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.date >= :from')
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('p.date', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Zmiany od $from, których mail nie jest oznaczony jako wysłany – do przypomnienia i banera na stronie dnia.
     *
     * @return PlannedChange[]
     */
    public function findUnsentFrom(\DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.date >= :from')
            ->andWhere('p.mailDoneAt IS NULL')
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('p.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return PlannedChange[] */
    public function findPast(\DateTimeImmutable $before, int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.date < :before')
            ->setParameter('before', $before->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('p.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return PlannedChange[] */
    public function findForDate(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.date = :d')
            ->setParameter('d', $date->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
