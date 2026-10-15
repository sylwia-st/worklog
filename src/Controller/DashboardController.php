<?php

namespace App\Controller;

use App\Repository\PlannedChangeRepository;
use App\Repository\WorkDayRepository;
use App\Service\ReportBuilder;
use App\Service\Settings;
use App\Service\WorkCalendar;
use App\Service\WorklogSync;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request, WorkDayRepository $days, PlannedChangeRepository $planned, ReportBuilder $reports, WorklogSync $sync, Settings $settings, WorkCalendar $calendar): Response
    {
        // kolejność dni: ?sort=asc|desc z nagłówka tabeli, zapamiętana na kolejne wejścia
        $sort = $request->query->getString('sort');
        if (in_array($sort, ['asc', 'desc'], true)) {
            if ($sort !== $settings->get('dashboard_sort')) {
                $settings->save(['dashboard_sort' => $sort]);
            }
        } else {
            $sort = 'asc' === $settings->get('dashboard_sort') ? 'asc' : 'desc';
        }

        $today = new \DateTimeImmutable('today');
        $from = $today->modify('-27 days');
        $saved = $days->findBetween($from, $today);
        $off = $calendar->daysOff($from, $today);

        $rows = [];
        for ($d = $today; $d >= $from; $d = $d->modify('-1 day')) {
            $key = $d->format('Y-m-d');
            $day = $saved[$key] ?? null;
            $weekend = (int) $d->format('N') >= 6;
            if ($weekend && null === $day) {
                continue;
            }

            $row = ['date' => $d, 'day' => $day, 'weekend' => $weekend, 'off' => $off[$key] ?? null];
            if (null !== $day && [] !== $day->getActiveEntries()) {
                $summary = $reports->summary($day);
                $statuses = $sync->statuses($day);
                $row += [
                    'summary' => $summary,
                    'entries' => count($statuses),
                    'inJira' => count(array_filter($statuses, static fn (string $s) => WorklogSync::STATUS_SYNCED === $s)),
                    'jiraDone' => $sync->isFullySynced($day),
                    'mailNeeded' => null !== $reports->presenceMail($day),
                ];
            }
            $rows[] = $row;
        }
        $chronological = array_reverse($rows);
        if ('asc' === $sort) {
            $rows = $chronological;
        }

        return $this->render('dashboard.html.twig', [
            'rows' => $rows,
            'sort' => $sort,
            'tiles' => $this->tiles($chronological, $today, $settings->int('norm_minutes'), $planned->findUnsentFrom($today)),
            'chart' => $this->chart($chronological, $today, $settings->int('norm_minutes')),
            'upcoming' => $planned->findFrom($today, 10),
            'today' => $today,
            'jiraConfigured' => $settings->jiraConfigured(),
        ]);
    }

    /**
     * Kafelki nad tabelą: bieżący tydzień, bilans 4 tygodni, dni bez raportu, maile do wysłania.
     *
     * @param list<array<string, mixed>> $rows dni od najstarszego
     * @param array<mixed>               $unsentPlanned
     *
     * @return array<string, int>
     */
    private function tiles(array $rows, \DateTimeImmutable $today, int $norm, array $unsentPlanned): array
    {
        $monday = $today->modify('monday this week');
        $out = ['week' => 0, 'weekNorm' => 0, 'balance' => 0, 'missing' => 0, 'mails' => count($unsentPlanned)];
        foreach ($rows as $r) {
            $isWorkday = !$r['weekend'] && null === $r['off'];
            if ($r['date'] >= $monday && $isWorkday) {
                $out['weekNorm'] += $norm;
            }
            if (isset($r['summary'])) {
                if ($r['date'] >= $monday) {
                    $out['week'] += $r['summary']->basisMinutes();
                }
                $out['balance'] += $r['summary']->difference();
                if ($r['mailNeeded'] && null === $r['day']->getMailDoneAt()) {
                    ++$out['mails'];
                }
            } elseif ($isWorkday && $r['date'] < $today) {
                ++$out['missing'];
            }
        }

        return $out;
    }

    /**
     * Słupki dni roboczych (i weekendów z pracą) – czas pracy względem normy.
     *
     * @param list<array<string, mixed>> $rows dni od najstarszego
     *
     * @return array{bars: list<array<string, mixed>>, normPct: float, norm: int}
     */
    private function chart(array $rows, \DateTimeImmutable $today, int $norm): array
    {
        $max = max([$norm] + array_map(static fn (array $r) => isset($r['summary']) ? $r['summary']->basisMinutes() : 0, $rows));
        $scale = max(60, (int) ceil($max * 1.15 / 60) * 60);
        $bars = [];
        foreach ($rows as $r) {
            $s = $r['summary'] ?? null;
            $kind = match (true) {
                null !== $s => 'done',
                $r['date'] > $today => 'future',
                null !== $r['off'] && 'weekend' !== $r['off']['kind'] => 'off',
                $r['date'] == $today => 'future',
                default => 'missing',
            };
            $minutes = null !== $s ? $s->basisMinutes() : 0;
            $bars[] = [
                'date' => $r['date'],
                'kind' => $kind,
                'minutes' => $minutes,
                'over' => null !== $s && 'over' === $s->kind(),
                'pct' => round($minutes / $scale * 100, 2),
                'summary' => $s,
                'off' => $r['off'],
            ];
        }

        return ['bars' => $bars, 'normPct' => round($norm / $scale * 100, 2), 'norm' => $norm];
    }
}
