<?php

namespace App\Controller;

use App\Domain\Holidays;
use App\Domain\Template;
use App\Entity\Absence;
use App\Repository\AbsenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Urlopy, L4 i inne dni wolne (zakresy dat) oraz najbliższe święta. */
final class AbsenceController extends AbstractController
{
    public function __construct(
        private readonly AbsenceRepository $absences,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/wolne', name: 'absences', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderPage([
            'from' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'to' => '',
            'type' => Absence::TYPE_LEAVE,
            'note' => '',
        ]);
    }

    /** Dodanie z formularza na tej stronie albo jednym kliknięciem ze strony dnia (wtedy z polem back). */
    #[Route('/wolne', name: 'absence_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $p = $request->request;
        $form = [
            'from' => trim((string) $p->get('from', '')),
            'to' => trim((string) $p->get('to', '')),
            'type' => (string) $p->get('type', ''),
            'note' => mb_substr(trim((string) $p->get('note', '')), 0, 255),
        ];
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $form['from']) ?: null;
        $to = '' === $form['to'] ? $from : (\DateTimeImmutable::createFromFormat('!Y-m-d', $form['to']) ?: null);

        $errors = [];
        if (null === $from || null === $to) {
            $errors[] = 'Podaj datę „od” (puste „do” = jeden dzień).';
        } elseif ($to < $from) {
            $errors[] = 'Data „do” nie może być wcześniejsza niż „od”.';
        } elseif ($from->diff($to)->days > 366) {
            $errors[] = 'Naraz można dodać najwyżej rok.';
        } elseif ([] !== $overlap = $this->absences->findOverlapping($from, $to)) {
            $errors[] = sprintf('W tych dniach jest już: %s, %s. Usuń to albo wybierz inne dni.', $overlap[0]->getLabel(), $overlap[0]->getRangeLabel());
        }
        if (!isset(Absence::TYPES[$form['type']])) {
            $errors[] = 'Wybierz rodzaj: urlop, L4 albo dzień wolny.';
        }

        $back = self::backPath($request);
        if ([] !== $errors) {
            foreach ($errors as $e) {
                $this->addFlash('error', $e);
            }

            return null !== $back ? $this->redirect($back) : $this->renderPage($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $absence = new Absence($from, $to, $form['type'], $form['note']);
        $this->em->persist($absence);
        $this->em->flush();
        $this->addFlash('success', sprintf('Zapisano: %s, %s (%s).', $absence->getLabel(), $absence->getRangeLabel(),
            Template::plural(Holidays::workingDays($from, $to), 'dzień roboczy', 'dni robocze', 'dni roboczych')));

        return null !== $back ? $this->redirect($back) : $this->redirectToRoute('absences');
    }

    #[Route('/wolne/{id}/delete', name: 'absence_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(#[MapEntity(id: 'id')] Absence $absence, Request $request): Response
    {
        $label = $absence->getLabel().', '.$absence->getRangeLabel();
        $this->em->remove($absence);
        $this->em->flush();
        $this->addFlash('success', 'Usunięto: '.$label.'.');
        $back = self::backPath($request);

        return null !== $back ? $this->redirect($back) : $this->redirectToRoute('absences');
    }

    /** @param array<string, string> $form */
    private function renderPage(array $form, int $status = 200): Response
    {
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $upcoming = $this->absences->findFrom($today);
        $past = $this->absences->findPast($today);

        $workDays = [];
        foreach ([...$upcoming, ...$past] as $a) {
            $workDays[$a->getId()] = Holidays::workingDays($a->getDateFrom(), $a->getDateTo());
        }
        // ile dni roboczych w tym roku zajęły nieobecności – osobno urlop, L4, dni wolne
        $yearStart = new \DateTimeImmutable("$year-01-01");
        $yearEnd = new \DateTimeImmutable("$year-12-31");
        $stats = [];
        foreach ($this->absences->findOverlapping($yearStart, $yearEnd) as $a) {
            $stats[$a->getTypeLabel()] = ($stats[$a->getTypeLabel()] ?? 0)
                + Holidays::workingDays(max($a->getDateFrom(), $yearStart), min($a->getDateTo(), $yearEnd));
        }

        // najbliższe święta – od dziś przez rok
        $holidays = [];
        $until = $today->modify('+1 year')->format('Y-m-d');
        foreach ([$year, $year + 1] as $y) {
            foreach (Holidays::forYear($y) as $date => $name) {
                if ($date >= $today->format('Y-m-d') && $date <= $until) {
                    $holidays[$date] = $name;
                }
            }
        }

        return $this->render('absence/index.html.twig', [
            'form' => $form,
            'types' => Absence::TYPES,
            'upcoming' => $upcoming,
            'past' => $past,
            'workDays' => $workDays,
            'stats' => $stats,
            'year' => $year,
            'holidays' => $holidays,
        ], new Response(status: $status));
    }

    /** Powrót na stronę, z której wysłano formularz – tylko ścieżka w tej aplikacji. */
    private static function backPath(Request $request): ?string
    {
        $back = (string) $request->request->get('back', '');

        return str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_contains($back, '\\') ? $back : null;
    }
}
