<?php

namespace App\Repository;

use App\Entity\Absence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Absence> */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    /** @return Absence[] nieobecności nachodzące na zakres [from, to], od najwcześniejszej */
    public function findOverlapping(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.dateFrom <= :to')
            ->andWhere('a.dateTo >= :from')
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('to', $to->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('a.dateFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Absence[] trwające albo zaplanowane (kończą się $from lub później), od najbliższej */
    public function findFrom(\DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.dateTo >= :from')
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('a.dateFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Absence[] zakończone przed $before, od najnowszej */
    public function findPast(\DateTimeImmutable $before, int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.dateTo < :before')
            ->setParameter('before', $before->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('a.dateFrom', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
