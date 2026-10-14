<?php

namespace App\Service;

use App\Domain\DaySummary;
use App\Domain\Template;
use App\Entity\WorkDay;

/**
 * Podsumowanie miesiąca: bilans dzień po dniu, sumy nadgodzin i niedoborów, czas per ticket, dni wolne.
 * Bilans liczy tylko dni z raportem; dzień roboczy bez raportu jest osobno („brak raportu”), bo nie wiadomo,
 * ile wtedy pracowano. Dziś bez raportu to jeszcze nie brak.
 */
final class MonthReport
{
    public const REPORTED = 'reported';
    public const MISSING = 'missing';
    public const TODAY = 'today';
    public const FUTURE = 'future';
    public const OFF = 'off';

    public function __construct(
        private readonly ReportBuilder $reports,
        private readonly WorkCalendar $calendar,
        private readonly Settings $settings,
    ) {
    }

    /** Pierwszy dzień miesiąca z „2026-10”; null przy złym formacie. */
    public static function parseMonth(string $raw): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $raw, $m) || (int) $m[1] < 2000 || (int) $m[1] > 2100) {
            return null;
        }

        return new \DateTimeImmutable("$m[1]-$m[2]-01");
    }

    /**
     * @param array<string, WorkDay> $saved dni z bazy z tego miesiąca (klucz Y-m-d)
     *
     * @return array{
     *     rows: list<array{date: \DateTimeImmutable, status: string, day: ?WorkDay, off: ?array, summary: ?DaySummary}>,
     *     totals: array<string, int>,
     *     offCounts: array<string, int>,
     *     tickets: array<string, array{minutes: int, days: int, title: string}>
     * }
     */
    public function build(\DateTimeImmutable $month, array $saved, \DateTimeImmutable $today): array
    {
        $first = $month->modify('first day of this month')->setTime(0, 0);
        $last = $first->modify('last day of this month');
        $today = $today->setTime(0, 0);
        $off = $this->calendar->daysOff($first, $last);
        $norm = $this->settings->int('norm_minutes');
        $titles = $this->settings->issueCache();

        $rows = [];
        $totals = array_fill_keys(['workingDays', 'normMonth', 'normSoFar', 'reported', 'missing',
            'worked', 'logged', 'normReported', 'balance', 'over', 'under', 'overDays', 'underDays'], 0);
        $offCounts = [];
        $tickets = [];

        for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $day = $saved[$key] ?? null;
            $dayOff = $off[$key] ?? null;
            $weekend = (int) $d->format('N') >= 6;
            $summary = null !== $day && [] !== $day->getActiveEntries() ? $this->reports->summary($day) : null;
            if (null !== $summary && DaySummary::KIND_EMPTY === $summary->kind()) {
                $summary = null;
            }

            if (null === $dayOff) {
                ++$totals['workingDays'];
                $totals['normMonth'] += $norm;
                if ($d <= $today) {
                    $totals['normSoFar'] += $norm;
                }
            } elseif (!$weekend) {
                // urlop, L4, święto w dzień powszedni – soboty i niedziele nie są „dniem urlopu”
                $label = WorkCalendar::ABSENCE === $dayOff['kind'] ? $dayOff['absence']->getTypeLabel() : 'Święto';
                $offCounts[$label] = ($offCounts[$label] ?? 0) + 1;
            }

            $status = match (true) {
                null !== $summary => self::REPORTED,
                null !== $dayOff => self::OFF,
                $d > $today => self::FUTURE,
                $d == $today => self::TODAY,
                default => self::MISSING,
            };
            if ($weekend && self::OFF === $status) {
                continue; // weekend bez pracy – nie ma czego pokazywać
            }
            $rows[] = ['date' => $d, 'status' => $status, 'day' => $day, 'off' => $dayOff, 'summary' => $summary];

            if (self::MISSING === $status) {
                ++$totals['missing'];
            }
            if (null === $summary) {
                continue;
            }
            ++$totals['reported'];
            $diff = $summary->difference();
            $totals['worked'] += $summary->basisMinutes();
            $totals['logged'] += $summary->loggedMinutes;
            $totals['normReported'] += $summary->normMinutes;
            $totals['balance'] += $diff;
            if ($diff > 0) {
                $totals['over'] += $diff;
                $totals['overDays'] += DaySummary::KIND_OVER === $summary->kind() ? 1 : 0;
            } elseif ($diff < 0) {
                $totals['under'] -= $diff;
                $totals['underDays'] += DaySummary::KIND_UNDER === $summary->kind() ? 1 : 0;
            }

            $seen = [];
            foreach ($day->getActiveEntries() as $e) {
                if (0 === $e->getMinutes()) {
                    continue;
                }
                $k = $e->getIssueKey();
                $tickets[$k] ??= ['minutes' => 0, 'days' => 0, 'title' => $titles[$k] ?? ''];
                $tickets[$k]['minutes'] += $e->getMinutes();
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    ++$tickets[$k]['days'];
                }
            }
        }
        uasort($tickets, static fn (array $a, array $b) => $b['minutes'] <=> $a['minutes']);

        return ['rows' => $rows, 'totals' => $totals, 'offCounts' => $offCounts, 'tickets' => $tickets];
    }

    /**
     * CSV dzień po dniu (średnik i BOM – Excel w polskich ustawieniach otwiera to bez kreatora). Czasy jako 7:30.
     *
     * @param array{rows: list<array>, totals: array<string, int>} $report
     */
    public function daysCsv(array $report): string
    {
        $lines = [['Data', 'Dzień', 'Od', 'Do', 'Przerwa', 'Czas pracy', 'W ticketach', 'Norma', 'Bilans', 'Uwagi']];
        foreach ($report['rows'] as $r) {
            $s = $r['summary'];
            $note = match ($r['status']) {
                self::MISSING => 'brak raportu',
                self::TODAY, self::FUTURE => '',
                default => null !== $r['off'] && WorkCalendar::WEEKEND !== $r['off']['kind'] ? $r['off']['label'] : '',
            };
            $lines[] = [
                $r['date']->format('Y-m-d'),
                Template::dayName($r['date']),
                $s ? ($r['day']->getStartTime() ?? '') : '',
                $s ? ($r['day']->getEndTime() ?? '') : '',
                $s ? self::clock($s->breakMinutes) : '',
                $s ? self::clock($s->basisMinutes()) : '',
                $s ? self::clock($s->loggedMinutes) : '',
                $s ? self::clock($s->normMinutes) : '',
                $s ? self::clock($s->difference(), true) : '',
                $note,
            ];
        }
        $t = $report['totals'];
        $lines[] = ['Razem', '', '', '', '', self::clock($t['worked']), self::clock($t['logged']), self::clock($t['normReported']),
            self::clock($t['balance'], true), $t['missing'] > 0 ? 'dni bez raportu: '.$t['missing'] : ''];

        return self::csv($lines);
    }

    /**
     * CSV wszystkich wpisów miesiąca – jak w Jirze: data, ticket, czas, opis.
     *
     * @param array{rows: list<array>, tickets: array<string, array{title: string}>} $report
     */
    public function entriesCsv(array $report): string
    {
        $lines = [['Data', 'Ticket', 'Tytuł', 'Czas', 'Minuty', 'Opis', 'W Jirze']];
        foreach ($report['rows'] as $r) {
            if (null === $r['summary']) {
                continue;
            }
            foreach ($r['day']->getActiveEntries() as $e) {
                if (0 === $e->getMinutes()) {
                    continue;
                }
                $lines[] = [
                    $r['date']->format('Y-m-d'),
                    $e->getIssueKey(),
                    $report['tickets'][$e->getIssueKey()]['title'] ?? '',
                    self::clock($e->getMinutes()),
                    (string) $e->getMinutes(),
                    $e->getDescription(),
                    $e->isInJira() ? 'tak' : 'nie',
                ];
            }
        }

        return self::csv($lines);
    }

    /** 450 → „7:30”, ze znakiem: „+1:15”, „-0:30”, „0:00”. */
    public static function clock(int $minutes, bool $signed = false): string
    {
        $sign = $minutes < 0 ? '-' : ($signed && $minutes > 0 ? '+' : '');

        return sprintf('%s%d:%02d', $sign, intdiv(abs($minutes), 60), abs($minutes) % 60);
    }

    /** @param list<list<string>> $lines */
    private static function csv(array $lines): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($lines as $line) {
            // tekst zaczynający się od =, +, - albo @ arkusz potraktowałby jak formułę – poza naszymi czasami („+1:15”)
            $line = array_map(static fn (string $v) => preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^[+-]\d+:\d\d$/', $v) ? "'".$v : $v, $line);
            fputcsv($out, $line, ';', '"', '', "\r\n");
        }
        rewind($out);

        return "\u{FEFF}".stream_get_contents($out);
    }
}
