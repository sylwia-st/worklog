<?php

namespace App\Controller;

use App\Repository\PlannedChangeRepository;
use App\Repository\WorkDayRepository;
use App\Service\ReportBuilder;
use App\Service\Settings;
use App\Service\WorklogSync;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request, WorkDayRepository $days, PlannedChangeRepository $planned, ReportBuilder $reports, WorklogSync $sync, Settings $settings): Response
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

        $rows = [];
        for ($d = $today; $d >= $from; $d = $d->modify('-1 day')) {
            $key = $d->format('Y-m-d');
            $day = $saved[$key] ?? null;
            $weekend = (int) $d->format('N') >= 6;
            if ($weekend && null === $day) {
                continue;
            }

            $row = ['date' => $d, 'day' => $day, 'weekend' => $weekend];
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
        if ('asc' === $sort) {
            $rows = array_reverse($rows);
        }

        return $this->render('dashboard.html.twig', [
            'rows' => $rows,
            'sort' => $sort,
            'upcoming' => $planned->findFrom($today, 10),
            'today' => $today,
        ]);
    }
}
