<?php

namespace App\Controller;

use App\Domain\TimeFormat;
use App\Entity\PlannedChange;
use App\Repository\PlannedChangeRepository;
use App\Service\ReportBuilder;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlannedController extends AbstractController
{
    public function __construct(
        private readonly PlannedChangeRepository $repo,
        private readonly ReportBuilder $reports,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/planned', name: 'planned', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderList([
            'date' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            'type' => PlannedChange::TYPE_LATE,
            'from' => '', 'to' => '', 'reason' => '',
        ]);
    }

    #[Route('/planned', name: 'planned_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $p = $request->request;
        $form = [
            'date' => trim((string) $p->get('date', '')),
            'type' => (string) $p->get('type', ''),
            'from' => trim((string) $p->get('from', '')),
            'to' => trim((string) $p->get('to', '')),
            'reason' => trim((string) $p->get('reason', '')),
        ];

        $errors = [];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $form['date']);
        if (false === $date) {
            $errors[] = 'Podaj datę.';
        }
        if (!isset(PlannedChange::TYPES[$form['type']])) {
            $errors[] = 'Wybierz rodzaj zmiany.';
        }
        $from = '' !== $form['from'] ? TimeFormat::normalizeClock($form['from']) : null;
        $to = '' !== $form['to'] ? TimeFormat::normalizeClock($form['to']) : null;
        if (('' !== $form['from'] && null === $from) || ('' !== $form['to'] && null === $to)) {
            $errors[] = 'Godziny wpisz w formacie GG:MM.';
        }
        $needsFrom = in_array($form['type'], [PlannedChange::TYPE_LATE, PlannedChange::TYPE_SHORT], true);
        $needsTo = in_array($form['type'], [PlannedChange::TYPE_EARLY, PlannedChange::TYPE_SHORT], true);
        if (($needsFrom && null === $from) || ($needsTo && null === $to)) {
            $errors[] = match ($form['type']) {
                PlannedChange::TYPE_LATE => 'Podaj, o której przyjdziesz.',
                PlannedChange::TYPE_EARLY => 'Podaj, o której wyjdziesz.',
                default => 'Podaj godziny od i do.',
            };
        }

        if ([] !== $errors) {
            foreach ($errors as $e) {
                $this->addFlash('error', $e);
            }

            return $this->renderList($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $change = new PlannedChange($date, $form['type'], $needsFrom ? $from : null, $needsTo ? $to : null, $form['reason']);
        $this->em->persist($change);
        $this->em->flush();

        return $this->redirectToRoute('planned_show', ['id' => $change->getId()]);
    }

    #[Route('/planned/{id}', name: 'planned_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] PlannedChange $change): Response
    {
        $notice = $this->reports->hoursOfNotice($change);

        return $this->render('planned/show.html.twig', [
            'change' => $change,
            'mail' => $this->reports->plannedMail($change),
            'noticeHours' => $notice,
            'requiredNotice' => $this->settings->int('plan_notice_hours'),
        ]);
    }

    #[Route('/planned/{id}/mark', name: 'planned_mark', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function mark(#[MapEntity(id: 'id')] PlannedChange $change): Response
    {
        $change->markMailDone();
        $this->em->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/planned/{id}/delete', name: 'planned_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(#[MapEntity(id: 'id')] PlannedChange $change): Response
    {
        $this->em->remove($change);
        $this->em->flush();
        $this->addFlash('success', 'Usunięto zaplanowaną zmianę.');

        return $this->redirectToRoute('planned');
    }

    /** @param array<string, string> $form */
    private function renderList(array $form, int $status = 200): Response
    {
        $today = new \DateTimeImmutable('today');

        return $this->render('planned/index.html.twig', [
            'form' => $form,
            'types' => PlannedChange::TYPES,
            'upcoming' => $this->repo->findFrom($today),
            'past' => $this->repo->findPast($today),
            'defaultStart' => $this->settings->get('default_start'),
            'defaultEnd' => $this->settings->get('default_end'),
        ], new Response(status: $status));
    }
}
