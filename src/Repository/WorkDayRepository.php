<?php

namespace App\Repository;

use App\Entity\WorkDay;
use App\Entity\WorklogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WorkDay> */
class WorkDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkDay::class);
    }

    public function findByDate(\DateTimeImmutable $date): ?WorkDay
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.date = :date')
            ->setParameter('date', $date->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return array<string, WorkDay> klucz: Y-m-d */
    public function findBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $days = $this->createQueryBuilder('d')
            ->leftJoin('d.entries', 'e')->addSelect('e')
            ->andWhere('d.date BETWEEN :from AND :to')
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('to', $to->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($days as $day) {
            $out[$day->getDate()->format('Y-m-d')] = $day;
        }

        return $out;
    }

    public function findPreviousWithEntries(\DateTimeImmutable $before): ?WorkDay
    {
        return $this->createQueryBuilder('d')
            ->innerJoin('d.entries', 'e')
            ->andWhere('d.date < :before')
            ->andWhere('e.deleted = false')
            ->setParameter('before', $before->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('d.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return string[] ostatnio używane numery ticketów */
    public function recentIssueKeys(\DateTimeImmutable $since): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('e.issueKey AS k, MAX(d.date) AS last')
            ->from(WorklogEntry::class, 'e')
            ->innerJoin('e.day', 'd')
            ->andWhere('d.date >= :since')
            ->setParameter('since', $since->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->groupBy('e.issueKey')
            ->orderBy('last', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'k');
    }

    /**
     * Tytuły ticketów wpisane przy wpisach (najnowszy wygrywa) – podpowiedź przy kolejnych dniach.
     *
     * @return array<string, string> klucz → tytuł
     */
    public function knownIssueTitles(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('e.issueKey AS k, e.issueTitle AS t')
            ->from(WorklogEntry::class, 'e')
            ->innerJoin('e.day', 'd')
            ->andWhere("e.issueTitle <> ''")
            ->orderBy('d.date', 'ASC')
            ->addOrderBy('e.position', 'ASC');
        if (null !== $since) {
            $qb->andWhere('d.date >= :since')->setParameter('since', $since->setTime(0, 0), Types::DATE_IMMUTABLE);
        }
        $out = [];
        foreach ($qb->getQuery()->getArrayResult() as $r) {
            $out[$r['k']] = $r['t'];
        }

        return $out;
    }
}
