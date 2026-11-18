<?php

namespace App\Service;

use App\Domain\EntryInput;
use App\Domain\ReusableEntries;
use App\Entity\WorkDay;
use App\Entity\WorklogEntry;
use App\Repository\WorkDayRepository;

final readonly class DayEntriesWriter
{
    public function __construct(
        private WorkDayRepository $days,
        private Settings $settings,
    ) {
    }

    public function replace(WorkDay $day, array $inputs): void
    {
        $running = array_find($day->getActiveEntries(), static fn (WorklogEntry $entry): bool => $entry->isTimerRunning());
        if (null !== $running) {
            throw new \DomainException(sprintf('Przy tickecie %s działa stoper – zatrzymaj go w aplikacji i spróbuj ponownie.', $running->getIssueKey()));
        }

        $titles = $this->days->knownIssueTitles() + $this->settings->issueCache();
        $reusable = new ReusableEntries($day->getActiveEntries());
        foreach (array_values($inputs) as $position => $input) {
            $entry = $reusable->takeFor($input) ?? new WorklogEntry($day, $input->issueKey, 0, '', $position);
            $entry->update($input->issueKey, $input->minutes, $input->description, $position);
            $entry->setStartClock(null);
            $entry->setIssueTitle($input->title ?: $entry->getIssueTitle() ?: $titles[$input->issueKey] ?? '');
        }

        foreach ($reusable->remaining() as $entry) {
            $entry->isInJira() ? $entry->markDeleted() : $day->removeEntry($entry);
        }
    }
}
