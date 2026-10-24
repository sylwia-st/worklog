<?php

namespace App\Controller;

use App\Entity\WorkDay;
use App\Repository\WorkDayRepository;
use App\Service\DayDefaults;
use App\Service\TogglImport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class TogglController extends AbstractController
{
    public function __construct(
        private readonly WorkDayRepository $days,
        private readonly TogglImport $import,
        private readonly DayDefaults $defaults,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/day/{date}/toggl', name: 'day_toggl', requirements: ['date' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    public function import(string $date): Response
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $d || $d->format('Y-m-d') !== $date) {
            throw new NotFoundHttpException('Niepoprawna data.');
        }

        $day = $this->days->findByDate($d);
        $isNew = null === $day;
        if ($isNew) {
            // dzień bez zapisu dostaje te same godziny, które pokazałaby jego pusta strona
            $defaults = $this->defaults->for($d);
            $day = new WorkDay($d);
            $day->setStartTime($defaults['start']);
            $day->setEndTime($defaults['end']);
            $day->setBreakMinutes($defaults['breakMinutes']);
        }

        $results = $this->import->import($day);
        // błędy na górze – nie mogą zginąć pod ostrzeżeniami
        usort($results, static fn (array $a, array $b): int => ('error' === $b['type']) <=> ('error' === $a['type']));
        foreach ($results as $result) {
            $this->addFlash($result['type'], $result['message']);
        }

        // pobranie się udało (także gdy Toggl nie miał wpisów) – zostaje po nim ślad na stronie dnia
        $failed = [] !== array_filter($results, static fn (array $r): bool => 'error' === $r['type']);
        if (!$failed) {
            $day->markTogglSynced(new \DateTimeImmutable());
        }

        // nowego dnia z samym błędem nie zapisujemy – strona i tak pokaże te same domyślne godziny
        if (!$isNew || !$failed || [] !== $day->getEntries()->toArray()) {
            if ($isNew) {
                $this->em->persist($day);
            }
            $this->em->flush();
        }

        return $this->redirectToRoute('day', ['date' => $date]);
    }
}
