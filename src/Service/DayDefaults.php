<?php

namespace App\Service;

use App\Entity\PlannedChange;
use App\Repository\PlannedChangeRepository;

/** Godziny i przerwa, które dostaje nowy dzień: z ustawień, zaplanowanych zmian i dni wolnych. */
final class DayDefaults
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PlannedChangeRepository $planned,
        private readonly WorkCalendar $calendar,
    ) {
    }

    /** @return array{start: ?string, end: ?string, breakMinutes: int} */
    public function for(\DateTimeImmutable $d): array
    {
        if (null !== $this->calendar->dayOff($d)) {
            // dzień wolny: bez podpowiadanych godzin – inaczej domyślne 8:00–16:00 wyszłyby jako nadgodziny
            return ['start' => null, 'end' => null, 'breakMinutes' => 0];
        }
        $start = $this->settings->get('default_start');
        $end = $this->settings->get('default_end');
        // zaplanowana zmiana na ten dzień → podpowiadamy jej godziny
        foreach ($this->planned->findForDate($d) as $p) {
            if (in_array($p->getType(), [PlannedChange::TYPE_LATE, PlannedChange::TYPE_SHORT], true) && $p->getFromTime()) {
                $start = $p->getFromTime();
            }
            if (in_array($p->getType(), [PlannedChange::TYPE_EARLY, PlannedChange::TYPE_SHORT], true) && $p->getToTime()) {
                $end = $p->getToTime();
            }
        }

        return ['start' => $start, 'end' => $end, 'breakMinutes' => $this->settings->int('default_break')];
    }
}
