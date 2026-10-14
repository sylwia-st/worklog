<?php

namespace App\Controller;

use App\Domain\Template;
use App\Repository\WorkDayRepository;
use App\Service\MonthReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/** Podsumowanie miesiąca: bilans, nadgodziny i niedobory, czas per ticket, eksport do CSV. */
final class MonthController extends AbstractController
{
    public function __construct(
        private readonly WorkDayRepository $days,
        private readonly MonthReport $months,
    ) {
    }

    #[Route('/miesiac', name: 'month_current', methods: ['GET'])]
    public function current(Request $request): Response
    {
        // pole <input type="month"> wysyła ?m=2026-10
        $month = MonthReport::parseMonth($request->query->getString('m')) ?? new \DateTimeImmutable('first day of this month');

        return $this->redirectToRoute('month', ['month' => $month->format('Y-m')]);
    }

    #[Route('/miesiac/{month}', name: 'month', requirements: ['month' => '\d{4}-\d{2}'], methods: ['GET'])]
    public function show(string $month): Response
    {
        $first = MonthReport::parseMonth($month) ?? throw $this->createNotFoundException();
        $today = new \DateTimeImmutable('today');

        return $this->render('month.html.twig', [
            'month' => $first,
            'title' => Template::monthName($first),
            'prev' => $first->modify('-1 month'),
            'next' => $first->modify('+1 month'),
            'isCurrent' => $first->format('Y-m') === $today->format('Y-m'),
            'report' => $this->report($first, $today),
            'today' => $today,
        ]);
    }

    #[Route('/miesiac/{month}/{kind}.csv', name: 'month_csv', requirements: ['month' => '\d{4}-\d{2}', 'kind' => 'dni|wpisy'], methods: ['GET'])]
    public function csv(string $month, string $kind): Response
    {
        $first = MonthReport::parseMonth($month) ?? throw $this->createNotFoundException();
        $report = $this->report($first, new \DateTimeImmutable('today'));
        $body = 'dni' === $kind ? $this->months->daysCsv($report) : $this->months->entriesCsv($report);

        $response = new Response($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('raport-czasu-%s-%s.csv', $first->format('Y-m'), $kind)));

        return $response;
    }

    private function report(\DateTimeImmutable $first, \DateTimeImmutable $today): array
    {
        $saved = $this->days->findBetween($first, $first->modify('last day of this month'));

        return $this->months->build($first, $saved, $today);
    }
}
