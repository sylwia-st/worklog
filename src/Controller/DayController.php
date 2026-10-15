<?php

namespace App\Controller;

use App\Domain\DayAxis;
use App\Domain\DaySummary;
use App\Domain\Template;
use App\Domain\TimeFormat;
use App\Entity\PlannedChange;
use App\Entity\WorkDay;
use App\Entity\WorklogEntry;
use App\Repository\PlannedChangeRepository;
use App\Repository\WorkDayRepository;
use App\Service\ReportBuilder;
use App\Service\Settings;
use App\Service\WorkCalendar;
use App\Service\WorklogSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DayController extends AbstractController
{
    public function __construct(
        private readonly WorkDayRepository $days,
        private readonly PlannedChangeRepository $planned,
        private readonly ReportBuilder $reports,
        private readonly WorklogSync $sync,
        private readonly Settings $settings,
        private readonly WorkCalendar $calendar,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/today', name: 'today', methods: ['GET'])]
    public function today(): Response
    {
        return $this->redirectToRoute('day', ['date' => (new \DateTimeImmutable('today'))->format('Y-m-d')]);
    }

    #[Route('/day/{date}', name: 'day', requirements: ['date' => '\d{4}-\d{2}-\d{2}'], methods: ['GET'])]
    public function show(string $date, Request $request): Response
    {
        $d = $this->parseDate($date);
        $day = $this->days->findByDate($d);

        $form = null !== $day ? $this->formFromDay($day) : $this->emptyForm($d);

        if ($request->query->getBoolean('copy') && (null === $day || [] === $day->getActiveEntries())) {
            $prev = $this->days->findPreviousWithEntries($d);
            if (null !== $prev) {
                $form['rows'] = array_map(static fn (WorklogEntry $e) => [
                    'id' => 0, 'issue' => $e->getIssueKey(), 'title' => $e->getIssueTitle(), 'from' => '', 'to' => '', 'time' => '', 'desc' => '', 'status' => null, 'error' => null,
                ], $prev->getActiveEntries());
                $this->addFlash('info', 'Skopiowano tickety z '.$prev->getDate()->format('d.m.Y').' – uzupełnij czas i opisy, potem zapisz.');
            }
        }

        return $this->renderDay($d, $day, $form);
    }

    #[Route('/day/{date}', name: 'day_save', requirements: ['date' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    public function save(string $date, Request $request): Response
    {
        $d = $this->parseDate($date);
        $day = $this->days->findByDate($d);

        [$form, $errors] = $this->formFromRequest($request);
        // „Usuń zaznaczone” wysyła confirm_delete=save|sync zamiast action
        $confirmed = in_array($request->request->get('confirm_delete'), ['save', 'sync'], true);
        $action = (string) $request->request->get($confirmed ? 'confirm_delete' : 'action', 'save');
        if ([] !== $errors) {
            foreach (array_unique($errors) as $e) {
                $this->addFlash('error', $e);
            }

            return $this->renderDay($d, $day, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Usunięcie zapisanego wiersza nigdy nie dzieje się samo (Enter w polu, stara karta, wyłączony JS):
        // bez potwierdzenia zapisujemy resztę zmian, a zaznaczone wiersze czekają na „Usuń zaznaczone”.
        $active = null !== $day ? array_map(static fn (WorklogEntry $e): int => (int) $e->getId(), $day->getActiveEntries()) : [];
        $marked = [];
        foreach ($form['rows'] as $row) {
            if ($row['delete'] && in_array($row['id'], $active, true)) {
                $marked[] = $row['id'];
            }
        }
        if ([] !== $marked && !$confirmed) {
            $day = $this->applyForm($d, $day, $form, partial: true)['day'];
            $form = $this->formFromDay($day);
            foreach ($form['rows'] as $i => $row) {
                $form['rows'][$i]['delete'] = in_array($row['id'], $marked, true);
            }

            return $this->renderDay($d, $day, $form, confirmDelete: [
                'action' => $action,
                'rows' => array_values(array_filter($form['rows'], static fn (array $row): bool => $row['delete'])),
            ]);
        }

        $day = $this->applyForm($d, $day, $form)['day'];

        if ('sync' === $action) {
            foreach ($this->sync->sync($day) as $result) {
                $this->addFlash($result['type'], $result['message']);
            }
            $this->em->flush();
        } else {
            $this->addFlash('success', 'Zapisano.');
        }

        return $this->redirectToRoute('day', ['date' => $date]);
    }

    #[Route('/day/{date}/mark/{what}', name: 'day_mark', requirements: ['date' => '\d{4}-\d{2}-\d{2}', 'what' => 'slack|mail'], methods: ['POST'])]
    public function mark(string $date, string $what): Response
    {
        $day = $this->days->findByDate($this->parseDate($date)) ?? throw new NotFoundHttpException();
        'slack' === $what ? $day->markSlackDone() : $day->markMailDone();
        $this->em->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Autozapis z przeglądarki (co ~1 s po zmianie i przy zamykaniu karty).
     * Zapisuje wszystko, co jest poprawne; niepoprawne wiersze/pola zostawia bez zmian i zwraca błędy.
     */
    #[Route('/day/{date}/autosave', name: 'day_autosave', requirements: ['date' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    public function autosave(string $date, Request $request): JsonResponse
    {
        $d = $this->parseDate($date);
        [$form, $errors] = $this->formFromRequest($request);
        $result = $this->applyForm($d, $this->days->findByDate($d), $form, partial: true);
        $day = $result['day'];

        $rowErrors = [];
        foreach ($form['rows'] as $row) {
            if (null !== $row['error']) {
                $rowErrors[$row['key']] = $row['error'];
            }
        }

        return $this->json([
            'ok' => true,
            'savedAt' => (new \DateTimeImmutable())->format('H:i:s'),
            'ids' => $result['ids'],
            'minutes' => $result['minutes'],
            'ends' => $result['ends'],
            'statuses' => $this->sync->statuses($day),
            'rowErrors' => (object) $rowErrors,
            'dayErrors' => $form['dayErrors'],
            'pendingDeletions' => count($day->getPendingDeletions()),
        ]);
    }

    /** Prawa kolumna (bilans, Slack, mail) – odświeżana po autozapisie bez przeładowania strony. */
    #[Route('/day/{date}/panels', name: 'day_panels', requirements: ['date' => '\d{4}-\d{2}-\d{2}'], methods: ['GET'])]
    public function panels(string $date): Response
    {
        $d = $this->parseDate($date);
        $day = $this->days->findByDate($d);

        return $this->render('day/_live.html.twig', $this->panelVars($d, $day));
    }

    /**
     * Wspólny zapis formularza dla „Zapisz” i autozapisu.
     *
     * @param array<string, mixed> $form
     *
     * @return array{day: WorkDay, ids: array<string, int>, minutes: array<string, int>, ends: array<string, string>}
     */
    private function applyForm(\DateTimeImmutable $d, ?WorkDay $day, array $form, bool $partial = false): array
    {
        if (null === $day) {
            $day = new WorkDay($d);
            $this->em->persist($day);
        }
        if (!$partial || [] === $form['dayErrors']) {
            $day->setStartTime($form['start']);
            $day->setEndTime($form['end']);
            $day->setBreakMinutes($form['breakMinutes']);
            $day->setBreakText($form['break']);
            $day->setWorkedThroughFixedBreak($form['lunchWorked']);
        }

        $existing = [];
        foreach ($day->getEntries() as $e) {
            $existing[(int) $e->getId()] = $e;
        }

        $created = [];
        $position = 0;
        foreach ($form['rows'] as $row) {
            $entry = $row['id'] > 0 ? ($existing[$row['id']] ?? null) : null;
            if ($row['delete'] && $partial) {
                continue; // autozapis nigdy nie usuwa – usunięcie zatwierdza się przyciskiem „Zapisz”
            }
            if ($row['delete']) {
                if (null !== $entry) {
                    $entry->stopTimer(new \DateTimeImmutable());
                    $entry->isInJira() ? $entry->markDeleted() : $day->removeEntry($entry);
                }
                continue;
            }
            if (null !== $row['error']) {
                ++$position; // niepoprawny wiersz zostaje bez zmian (w autozapisie)
                continue;
            }
            if (null !== $entry) {
                // Czas zmieniamy tylko, gdy użytkownik go zmienił, i nigdy w trakcie działania stopera –
                // dzięki temu stara karta przeglądarki nie nadpisze czasu doliczonego przez stoper.
                $minutes = $entry->getMinutes();
                if (!$entry->isTimerRunning() && $row['minutes'] !== $row['origMinutes']) {
                    $minutes = $row['minutes'];
                }
                $entry->update($row['issue'], $minutes, $row['desc'], $position);
            } else {
                $entry = new WorklogEntry($day, $row['issue'], $row['minutes'], $row['desc'], $position);
                $this->em->persist($entry);
                $created[$row['key']] = $entry;
            }
            $entry->setStartClock($row['from']);
            $entry->setIssueTitle($row['title']);
            ++$position;
        }
        $this->em->flush();

        $ids = [];
        $minutes = [];
        $ends = [];
        foreach ($form['rows'] as $row) {
            $entry = $created[$row['key']] ?? ($row['id'] > 0 ? ($existing[$row['id']] ?? null) : null);
            if (null !== $entry && !$row['delete'] && null !== $entry->getId()) {
                $ids[$row['key']] = (int) $entry->getId();
                $minutes[$row['key']] = $entry->getMinutes();
                $ends[$row['key']] = $this->sync->endClock($entry) ?? '';
            }
        }

        return ['day' => $day, 'ids' => $ids, 'minutes' => $minutes, 'ends' => $ends];
    }

    /** @return array<string, mixed> */
    private function panelVars(\DateTimeImmutable $d, ?WorkDay $day): array
    {
        $hasEntries = null !== $day && [] !== $day->getActiveEntries();
        $summary = null !== $day ? $this->reports->summary($day) : null;
        $mail = null !== $day ? $this->reports->presenceMail($day) : null;

        // pasek domknięcia dnia: wpisy → Jira → Slack → mail
        $tickets = [];
        $count = 0;
        foreach ($hasEntries ? $day->getActiveEntries() : [] as $e) {
            if ($e->getMinutes() > 0) {
                ++$count;
                $tickets[$e->getIssueKey()] = ($tickets[$e->getIssueKey()] ?? 0) + $e->getMinutes();
            }
        }
        $sendable = array_filter($hasEntries ? $this->sync->statuses($day) : [], static fn (string $s) => WorklogSync::STATUS_EMPTY !== $s);
        $closing = [
            'entries' => $count,
            'logged' => $summary?->loggedMinutes ?? 0,
            'jiraTotal' => count($sendable),
            'jiraSynced' => count(array_filter($sendable, static fn (string $s) => WorklogSync::STATUS_SYNCED === $s)),
            'jiraErrors' => count(array_filter($sendable, static fn (string $s) => WorklogSync::STATUS_ERROR === $s)),
            'jiraDone' => $hasEntries && $this->sync->isFullySynced($day),
            'jiraConfigured' => $this->settings->jiraConfigured(),
        ];

        // oś dnia: obiad (jeśli nie przepracowany) i przerwy podane godzinami
        $breaks = $day?->getBreakRanges() ?? [];
        $lf = TimeFormat::clockToMinutes($this->settings->get('lunch_from') ?: '');
        $lt = TimeFormat::clockToMinutes($this->settings->get('lunch_to') ?: '');
        if (null !== $lf && null !== $lt && $lt > $lf && !($day?->hasWorkedThroughFixedBreak() ?? false)) {
            $breaks[] = [$lf, $lt];
        }
        $axis = DayAxis::build($day?->getStartTime(), $day?->getEndTime(), $breaks,
            array_map(null, array_keys($tickets), array_values($tickets)),
            $summary?->normMinutes ?? $this->settings->int('norm_minutes'), $summary?->presenceMinutes);

        return [
            'date' => $d,
            'day' => $day,
            'summary' => $summary,
            'slack' => $hasEntries ? $this->reports->slackText($day) : null,
            'mail' => $mail,
            'closing' => $closing,
            'axis' => $axis,
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @param array{action: string, rows: list<array<string, mixed>>}|null $confirmDelete wiersze czekające na potwierdzenie usunięcia
     */
    private function renderDay(\DateTimeImmutable $d, ?WorkDay $day, array $form, int $status = 200, ?array $confirmDelete = null): Response
    {
        $issueOptions = $this->settings->issueCache();
        foreach ($this->days->recentIssueKeys($d->modify('-60 days')) as $key) {
            $issueOptions[$key] ??= '';
        }
        // tytuły wpisane przy wpisach mają pierwszeństwo przed tytułami z Jiry
        foreach ($this->days->knownIssueTitles() as $key => $title) {
            $issueOptions[$key] = $title;
        }

        return $this->render('day/show.html.twig', $this->panelVars($d, $day) + [
            'prev' => $d->modify('-1 day'),
            'next' => $d->modify('+1 day'),
            'today' => new \DateTimeImmutable('today'),
            'form' => $form,
            'pendingDeletions' => null !== $day ? $day->getPendingDeletions() : [],
            'confirmDelete' => $confirmDelete,
            'dayOff' => $this->calendar->dayOff($d),
            'planned' => $this->planned->findForDate($d),
            // na dzisiejszej stronie: maile o zmianach w kolejnych dniach, które jeszcze czekają (te z dziś są w banerze wyżej)
            'unsentPlanned' => $d == new \DateTimeImmutable('today')
                ? $this->planned->findUnsentFrom($d->modify('+1 day'))
                : [],
            'issueOptions' => $issueOptions,
            'norm' => $this->settings->int('norm_minutes'),
            'lunch' => '' !== $this->settings->get('lunch_from') && '' !== $this->settings->get('lunch_to')
                ? $this->settings->get('lunch_from').'–'.$this->settings->get('lunch_to')
                : null,
            'lunchLength' => TimeFormat::format(max(1,
                (int) TimeFormat::clockToMinutes($this->settings->get('lunch_to') ?: '00:00')
                - (int) TimeFormat::clockToMinutes($this->settings->get('lunch_from') ?: '00:00'))),
        ], new Response(status: $status));
    }

    /** @return array<string, mixed> */
    private function formFromDay(WorkDay $day): array
    {
        $statuses = $this->sync->statuses($day);

        return [
            'start' => $day->getStartTime(),
            'end' => $day->getEndTime(),
            'break' => $day->getBreakText() ?? ($day->getBreakMinutes() > 0 ? TimeFormat::format($day->getBreakMinutes()) : ''),
            'lunchWorked' => $day->hasWorkedThroughFixedBreak(),
            'rows' => array_map(fn (WorklogEntry $e) => [
                'id' => (int) $e->getId(),
                'issue' => $e->getIssueKey(),
                'title' => $e->getIssueTitle(),
                'from' => $e->getStartClock() ?? '',
                'to' => $this->sync->endClock($e) ?? '',
                'time' => $e->getMinutes() > 0 ? TimeFormat::format($e->getMinutes()) : '',
                'desc' => $e->getDescription(),
                'status' => $statuses[(int) $e->getId()] ?? null,
                'error' => $e->getJiraError(),
            ], $day->getActiveEntries()),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyForm(\DateTimeImmutable $d): array
    {
        if (null !== $this->calendar->dayOff($d)) {
            // dzień wolny: bez podpowiadanych godzin – inaczej domyślne 8:00–16:00 wyszłyby jako nadgodziny
            return ['start' => null, 'end' => null, 'break' => '', 'lunchWorked' => false, 'rows' => []];
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
        $break = $this->settings->int('default_break');

        return [
            'start' => $start,
            'end' => $end,
            'break' => $break > 0 ? TimeFormat::format($break) : '',
            'lunchWorked' => false,
            'rows' => [],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: string[]} */
    private function formFromRequest(Request $request): array
    {
        $dayErrors = [];
        $p = $request->request;

        $start = $this->clockField((string) $p->get('start', ''), 'Godzina rozpoczęcia', $dayErrors);
        $end = $this->clockField((string) $p->get('end', ''), 'Godzina zakończenia', $dayErrors);
        if ((null === $start) !== (null === $end) && [] === $dayErrors) {
            $dayErrors[] = 'Podaj obie godziny (od i do) albo żadnej.';
        }

        $breakRaw = trim((string) $p->get('break', ''));
        $breakSpec = TimeFormat::parseBreakSpec($breakRaw);
        $breakMinutes = 0;
        if (null === $breakSpec) {
            $dayErrors[] = 'Niepoprawna przerwa – wpisz np. 30m, 1h, na raty: 20m + 40m, albo godziny: 13:30-13:50, 15:00-15:40.';
        } else {
            $breakMinutes = $breakSpec['minutes'];
        }
        $lunchWorked = $p->getBoolean('lunch_worked');
        // przerwy z formularza – czas z godzin od–do i godzina zakończenia je pomijają
        $windows = $this->sync->breakWindows($breakSpec['ranges'] ?? [], $lunchWorked);

        $errors = $dayErrors;
        $rows = [];
        foreach ($p->all('entries') as $key => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $row = [
                'key' => (string) $key,
                'id' => (int) ($raw['id'] ?? 0),
                'issue' => trim((string) ($raw['issue'] ?? '')),
                'title' => trim((string) ($raw['title'] ?? '')),
                'from' => trim((string) ($raw['from'] ?? '')),
                'to' => trim((string) ($raw['to'] ?? '')),
                'time' => trim((string) ($raw['time'] ?? '')),
                'desc' => trim((string) ($raw['desc'] ?? '')),
                'delete' => !empty($raw['delete']),
                'minutes' => 0,
                // czas widoczny w formularzu przy wczytaniu – porównujemy z nim, czy użytkownik zmienił czas
                'origMinutes' => TimeFormat::parse(trim((string) ($raw['orig'] ?? ''))) ?? 0,
                'status' => null,
                'error' => null,
            ];
            if (0 === $row['id'] && '' === $row['issue'] && '' === $row['title'] && '' === $row['from'] && '' === $row['to'] && '' === $row['time'] && '' === $row['desc']) {
                continue; // pusty wiersz
            }
            if (!$row['delete']) {
                $issue = Template::extractIssueKey($row['issue']);
                $minutes = '' === $row['time'] ? 0 : TimeFormat::parse($row['time']); // puste = 0m (np. czeka na stoper)
                $from = '' === $row['from'] ? null : TimeFormat::clockToMinutes($row['from']);
                $to = '' === $row['to'] ? null : TimeFormat::clockToMinutes($row['to']);
                if (null === $issue) {
                    $row['error'] = 'Niepoprawny numer ticketu (np. ABC-123).';
                } elseif (null === $from && '' !== $row['from'] || null === $to && '' !== $row['to']) {
                    $row['error'] = 'Godzina: wpisz w formacie GG:MM.';
                } elseif (null === $from && null !== $to && '' === $row['time']) {
                    $row['error'] = 'Podaj też godzinę rozpoczęcia (od).';
                } elseif (null === $minutes) {
                    $row['error'] = self::timeError($row['time']);
                } elseif (null !== $to && '' === $row['time'] && $to <= $from) {
                    $row['error'] = 'Godzina zakończenia musi być późniejsza niż rozpoczęcia.';
                } else {
                    // czas ma pierwszeństwo; z samych godzin od–do wyliczamy go (bez przerw w środku)
                    if (0 === $minutes && null !== $to) {
                        $minutes = DaySummary::workedBetween($from, $to, $windows);
                    }
                    $rowEnd = null !== $from ? DaySummary::advance($from, $minutes, $windows) : null;
                    if (null !== $to && 0 === $minutes) {
                        $row['error'] = 'Między tymi godzinami jest sama przerwa – popraw godziny.';
                    } elseif (null !== $rowEnd && $rowEnd > 24 * 60) {
                        $row['error'] = 'Wpis kończyłby się po północy – skróć czas albo zmień godzinę rozpoczęcia.';
                    } else {
                        // „ABC-123 Produkcja nie działa” w polu ticketu → numer + tytuł
                        if ('' === $row['title']) {
                            $row['title'] = Template::issueTitleAfterKey($row['issue']);
                        }
                        $row['issue'] = $issue;
                        $row['minutes'] = $minutes;
                        $row['from'] = null !== $from ? TimeFormat::minutesToClock($from) : null;
                        $row['to'] = null !== $rowEnd && $minutes > 0 ? TimeFormat::minutesToClock($rowEnd) : '';
                        $row['time'] = $minutes > 0 ? TimeFormat::format($minutes) : '';
                    }
                }
                if (null !== $row['error']) {
                    $errors[] = 'Popraw zaznaczone wiersze.';
                }
            }
            $rows[] = $row;
        }

        return [[
            'start' => $start,
            'end' => $end,
            'break' => $breakRaw,
            'breakMinutes' => $breakMinutes,
            'lunchWorked' => $lunchWorked,
            'rows' => $rows,
            'dayErrors' => $dayErrors,
        ], $errors];
    }

    /** Komunikat o złym czasie; przy samej liczbie typu „20” pytamy, czy chodzi o minuty, czy o godziny. */
    private static function timeError(string $raw): string
    {
        $n = TimeFormat::ambiguousNumber($raw);

        return match (true) {
            null === $n, $n > 24 * 60 => 'Niepoprawny czas – wpisz np. 1h 30m, 1.5, 90m albo 1:30.',
            $n <= 24 => sprintf('„%1$d” – minuty czy godziny? Wpisz %1$dm albo %1$dh.', $n),
            default => sprintf('„%1$d” – chodziło o minuty? Wpisz %1$dm.', $n),
        };
    }

    /** @param string[] $errors */
    private function clockField(string $raw, string $label, array &$errors): ?string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }
        $n = TimeFormat::normalizeClock($raw);
        if (null === $n) {
            $errors[] = $label.': wpisz godzinę w formacie GG:MM.';
        }

        return $n;
    }

    private function parseDate(string $date): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $d || $d->format('Y-m-d') !== $date) {
            throw new NotFoundHttpException('Niepoprawna data.');
        }

        return $d;
    }
}
