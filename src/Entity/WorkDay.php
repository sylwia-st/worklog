<?php

namespace App\Entity;

use App\Domain\TimeFormat;
use App\Repository\WorkDayRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkDayRepository::class)]
#[ORM\Table(name: 'work_day')]
#[ORM\UniqueConstraint(name: 'uniq_work_day_date', columns: ['day_date'])]
class WorkDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'day_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $startTime = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $endTime = null;

    /** Suma przerw podanych samym czasem trwania (np. "20m"). Przerwy z godzinami są w $breakText. */
    #[ORM\Column]
    private int $breakMinutes = 0;

    /** Przerwa dokładnie tak, jak ją wpisano, np. "13:30-13:50, 15:00-15:40". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $breakText = null;

    /** Praca w stałej przerwie (np. awaria w porze obiadu) – obiad nie jest odliczany, a faktyczną przerwę podaje się w breakMinutes. */
    #[ORM\Column(options: ['default' => false])]
    private bool $workedThroughFixedBreak = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $slackDoneAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $mailDoneAt = null;

    /** Kiedy ostatnio udało się pobrać wpisy z Toggla do tego dnia (null = nigdy). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $togglSyncedAt = null;

    /**
     * Godziny obecności ostatnio ustawione przez import z Toggla, np. {"start": "07:23", "end": "15:23"} –
     * pozwalają odróżnić godziny z Toggla od tych, które użytkownik poprawił ręcznie.
     *
     * @var array{start: string, end: string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $togglHours = null;

    /** @var Collection<int, WorklogEntry> */
    #[ORM\OneToMany(targetEntity: WorklogEntry::class, mappedBy: 'day', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $entries;

    public function __construct(\DateTimeImmutable $date)
    {
        $this->date = $date->setTime(0, 0);
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getStartTime(): ?string
    {
        return $this->startTime;
    }

    public function setStartTime(?string $startTime): void
    {
        $this->startTime = $startTime;
    }

    public function getEndTime(): ?string
    {
        return $this->endTime;
    }

    public function setEndTime(?string $endTime): void
    {
        $this->endTime = $endTime;
    }

    public function getBreakMinutes(): int
    {
        return $this->breakMinutes;
    }

    public function setBreakMinutes(int $breakMinutes): void
    {
        $this->breakMinutes = max(0, $breakMinutes);
    }

    public function getBreakText(): ?string
    {
        return $this->breakText;
    }

    public function setBreakText(?string $breakText): void
    {
        $breakText = null !== $breakText ? trim($breakText) : null;
        $this->breakText = '' === $breakText ? null : $breakText;
    }

    /** @return list<array{0: int, 1: int}> przerwy podane godzinami, w minutach od północy */
    public function getBreakRanges(): array
    {
        return null === $this->breakText ? [] : (TimeFormat::parseBreakSpec($this->breakText)['ranges'] ?? []);
    }

    public function hasWorkedThroughFixedBreak(): bool
    {
        return $this->workedThroughFixedBreak;
    }

    public function setWorkedThroughFixedBreak(bool $worked): void
    {
        $this->workedThroughFixedBreak = $worked;
    }

    public function getSlackDoneAt(): ?\DateTimeImmutable
    {
        return $this->slackDoneAt;
    }

    public function markSlackDone(): void
    {
        $this->slackDoneAt = new \DateTimeImmutable();
    }

    public function getMailDoneAt(): ?\DateTimeImmutable
    {
        return $this->mailDoneAt;
    }

    public function markMailDone(): void
    {
        $this->mailDoneAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, WorklogEntry> wszystkie wpisy, także oczekujące na usunięcie z Jiry */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    /** @return WorklogEntry[] */
    public function getActiveEntries(): array
    {
        return array_values($this->entries->filter(static fn (WorklogEntry $e) => !$e->isDeleted())->toArray());
    }

    /** @return WorklogEntry[] */
    public function getPendingDeletions(): array
    {
        return array_values($this->entries->filter(static fn (WorklogEntry $e) => $e->isDeleted())->toArray());
    }

    public function addEntry(WorklogEntry $entry): void
    {
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
        }
    }

    public function getTogglSyncedAt(): ?\DateTimeImmutable
    {
        return $this->togglSyncedAt;
    }

    public function markTogglSynced(\DateTimeImmutable $at): void
    {
        $this->togglSyncedAt = $at;
    }

    /** @return array{start: string, end: string}|null */
    public function getTogglHours(): ?array
    {
        return $this->togglHours;
    }

    /** @param array{start: string, end: string}|null $hours */
    public function setTogglHours(?array $hours): void
    {
        $this->togglHours = $hours;
    }

    public function removeEntry(WorklogEntry $entry): void
    {
        $this->entries->removeElement($entry);
    }
}
