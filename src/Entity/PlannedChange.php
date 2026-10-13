<?php

namespace App\Entity;

use App\Repository\PlannedChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlannedChangeRepository::class)]
#[ORM\Table(name: 'planned_change')]
class PlannedChange
{
    public const TYPE_LATE = 'late';
    public const TYPE_EARLY = 'early';
    public const TYPE_SHORT = 'short';

    public const TYPES = [
        self::TYPE_LATE => 'Późniejsze przyjście',
        self::TYPE_EARLY => 'Wcześniejsze wyjście',
        self::TYPE_SHORT => 'Krótszy dzień pracy',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'change_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 10)]
    private string $type;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $fromTime;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $toTime;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $mailDoneAt = null;

    public function __construct(\DateTimeImmutable $date, string $type, ?string $fromTime, ?string $toTime, string $reason)
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Nieznany typ zmiany: '.$type);
        }
        $this->date = $date->setTime(0, 0);
        $this->type = $type;
        $this->fromTime = $fromTime;
        $this->toTime = $toTime;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type];
    }

    public function getFromTime(): ?string
    {
        return $this->fromTime;
    }

    public function getToTime(): ?string
    {
        return $this->toTime;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMailDoneAt(): ?\DateTimeImmutable
    {
        return $this->mailDoneAt;
    }

    public function markMailDone(): void
    {
        $this->mailDoneAt = new \DateTimeImmutable();
    }
}
