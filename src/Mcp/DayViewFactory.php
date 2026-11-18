<?php

namespace App\Mcp;

use App\Domain\Template;
use App\Entity\WorkDay;
use App\Entity\WorklogEntry;
use App\Repository\WorkDayRepository;
use App\Service\ReportBuilder;
use App\Service\Settings;
use App\Service\WorkCalendar;
use App\Service\WorklogSync;

final readonly class DayViewFactory
{
    public function __construct(
        private WorkDayRepository $days,
        private WorklogSync $sync,
        private ReportBuilder $reports,
        private WorkCalendar $calendar,
        private Settings $settings,
    ) {
    }

    public function forDate(\DateTimeImmutable $date): DayView
    {
        $day = $this->days->findByDate($date) ?? new WorkDay($date);
        $statuses = $this->sync->statuses($day);
        $summary = $this->reports->summary($day);

        return new DayView(
            date: $date->format('Y-m-d'),
            weekday: Template::dayName($date),
            dayOff: $this->calendar->dayOff($date)['label'] ?? null,
            start: $day->getStartTime(),
            end: $day->getEndTime(),
            normMinutes: $summary->normMinutes,
            totalMinutes: $summary->loggedMinutes,
            entries: array_map(
                static fn (WorklogEntry $entry): EntryView => EntryView::of($entry, $statuses[$entry->getId()]),
                $day->getActiveEntries(),
            ),
            pendingJiraDeletions: count($day->getPendingDeletions()),
            jiraConfigured: $this->settings->jiraConfigured(),
        );
    }
}
