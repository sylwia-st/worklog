<?php

namespace App\Entity;

use App\Repository\AbsenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Nieobecność w pracy – urlop, L4 albo inny dzień wolny – od–do włącznie. */
#[ORM\Entity(repositoryClass: AbsenceRepository::class)]
#[ORM\Table(name: 'absence')]
class Absence
{
    public const TYPE_LEAVE = 'urlop';
    public const TYPE_SICK = 'l4';
    public const TYPE_OFF = 'wolne';
    public const TYPES = [
        self::TYPE_LEAVE => 'Urlop',
        self::TYPE_SICK => 'Zwolnienie lekarskie (L4)',
        self::TYPE_OFF => 'Dzień wolny',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateTo;

    #[ORM\Column(length: 10)]
    private string $type;

    /** np. „odbiór za święto 15.08” */
    #[ORM\Column(length: 255)]
    private string $note;

    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to, string $type, string $note = '')
    {
        $this->dateFrom = $from->setTime(0, 0);
        $this->dateTo = $to->setTime(0, 0);
        $this->type = $type;
        $this->note = $note;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateFrom(): \DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function getDateTo(): \DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** „Urlop” albo „Dzień wolny – odbiór za święto 15.08” */
    public function getLabel(): string
    {
        return $this->getTypeLabel().('' !== $this->note ? ' – '.$this->note : '');
    }

    /** „11.11.2026”, „10.11–14.11.2026” albo „29.12.2026–2.01.2027” */
    public function getRangeLabel(): string
    {
        $to = $this->dateTo->format('d.m.Y');
        if ($this->dateFrom == $this->dateTo) {
            return $to;
        }
        $from = $this->dateFrom->format($this->dateFrom->format('Y') === $this->dateTo->format('Y') ? 'd.m' : 'd.m.Y');

        return $from.'–'.$to;
    }
}
