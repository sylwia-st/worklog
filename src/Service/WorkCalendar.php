<?php

namespace App\Service;

use App\Domain\Holidays;
use App\Domain\Template;
use App\Entity\Absence;
use App\Repository\AbsenceRepository;

/**
 * Które dni są wolne: święta ustawowe, nieobecności ze strony „Wolne” (urlop, L4, dzień wolny) i weekendy.
 * W dzień wolny nie ma przypomnienia ani „brak raportu”, a norma wynosi 0 – praca tego dnia to nadgodziny.
 */
final class WorkCalendar
{
    public const HOLIDAY = 'holiday';
    public const ABSENCE = 'absence';
    public const WEEKEND = 'weekend';

    /** @var array<string, array{kind: string, label: string, absence: ?Absence}|false> Y-m-d → dzień wolny albo false (roboczy) */
    private array $known = [];

    public function __construct(private readonly AbsenceRepository $absences)
    {
    }

    /** @return array{kind: string, label: string, absence: ?Absence}|null null = dzień roboczy */
    public function dayOff(\DateTimeImmutable $date): ?array
    {
        $key = $date->format('Y-m-d');
        if (!array_key_exists($key, $this->known)) {
            $this->daysOff($date, $date);
        }

        return $this->known[$key] ?: null;
    }

    /**
     * Dni wolne w zakresie (włącznie) – jednym zapytaniem; zapamiętane także dla dayOff().
     * Święto ma pierwszeństwo przed nieobecnością (nie zajmuje dnia urlopu), a nieobecność przed weekendem.
     *
     * @return array<string, array{kind: string, label: string, absence: ?Absence}> Y-m-d → powód
     */
    public function daysOff(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $absences = $this->absences->findOverlapping($from, $to);
        $out = [];
        for ($d = $from->setTime(0, 0); $d <= $to; $d = $d->modify('+1 day')) {
            $absence = null;
            foreach ($absences as $a) {
                if ($a->getDateFrom() <= $d && $d <= $a->getDateTo()) {
                    $absence = $a;
                    break;
                }
            }
            $holiday = Holidays::name($d);
            $info = match (true) {
                null !== $holiday => ['kind' => self::HOLIDAY, 'label' => $holiday, 'absence' => $absence],
                null !== $absence => ['kind' => self::ABSENCE, 'label' => $absence->getLabel(), 'absence' => $absence],
                (int) $d->format('N') >= 6 => ['kind' => self::WEEKEND, 'label' => ucfirst(Template::dayName($d)), 'absence' => null],
                default => false,
            };
            $this->known[$d->format('Y-m-d')] = $info;
            if (false !== $info) {
                $out[$d->format('Y-m-d')] = $info;
            }
        }

        return $out;
    }
}
