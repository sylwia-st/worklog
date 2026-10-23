<?php

namespace App\Service;

use App\Domain\Template;
use App\Domain\TimeFormat;
use App\Entity\WorkDay;
use App\Entity\WorklogEntry;
use App\Repository\WorkDayRepository;

/**
 * Pobiera wpisy z Toggla Track z jednego dnia do wierszy dnia.
 *
 * Wpisy o tym samym (ticket, opis) składają się w jeden wiersz. Wiersz pamięta id wpisów Toggla (togglIds),
 * więc ponowne pobranie tego samego dnia aktualizuje czas zamiast dublować wiersze. Toggl jest źródłem czasu,
 * nie tekstów – ticket, opis i tytuł zmienione w aplikacji zostają.
 * Serwis nie robi flush() – robi go kontroler.
 */
final class TogglImport
{
    public function __construct(
        private readonly TogglClient $toggl,
        private readonly Settings $settings,
        private readonly WorkDayRepository $days,
        private readonly DayDefaults $defaults,
    ) {
    }

    /** @return list<array{type: string, message: string}> */
    public function import(WorkDay $day, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $messages = [];

        try {
            $groups = $this->collect($day, $now, $messages, $seen, $allIds, $hours);
        } catch (TogglException $e) {
            return [['type' => 'error', 'message' => $e->getMessage()]];
        }

        $existing = [];
        $position = -1;
        foreach ($day->getEntries() as $entry) {
            $position = max($position, $entry->getPosition());
            // wiersze czekające na skasowanie z Jiry nie są kandydatami – ich grupa daje nowy wiersz
            if (!$entry->isDeleted() && [] !== $entry->getTogglIds()) {
                $existing[] = $entry;
            }
        }
        usort($existing, static fn (WorklogEntry $a, WorklogEntry $b): int => $a->getPosition() <=> $b->getPosition());

        $titles = null;
        $new = $updated = $seconds = 0;
        $claimed = [];
        foreach ($groups as $group) {
            // wiersze, które powstały z tych samych wpisów Toggla (np. po ujednoliceniu opisu w Togglu)
            $matches = [];
            foreach ($existing as $i => $candidate) {
                if ([] !== array_intersect($candidate->getTogglIds(), $group['ids'])) {
                    $matches[$i] = $candidate;
                }
            }
            // „od” = start najwcześniejszego wpisu grupy; „do” wylicza się z czasu wiersza (przy przeplatanych odcinkach wypadnie wcześniej niż realny koniec)
            $startClock = $group['clock'];

            if ([] !== $matches) {
                foreach ($matches as $m) {
                    if ($m->isTimerRunning()) {
                        $messages[] = ['type' => 'warning', 'message' => sprintf('Przy tickecie %s działa stoper aplikacji – czasu z Toggla nie zmieniono. Zatrzymaj stoper i pobierz ponownie.', $m->getIssueKey())];
                        $claimed = array_merge($claimed, array_keys($matches));
                        continue 2;
                    }
                }
                $claimed = array_merge($claimed, array_keys($matches));
                $row = array_shift($matches);
                $row->setTrackedSeconds($group['seconds']);
                $row->setTogglIds($group['ids']);
                $row->setStartClock($startClock);
                ++$updated;
                // pozostałe wiersze usuwamy tak jak użytkownik
                foreach ($matches as $extra) {
                    $inJira = $extra->isInJira();
                    $messages[] = ['type' => 'warning', 'message' => sprintf('Wpisy z Toggla połączyły się: czas wiersza „%s” (%s, %s) przeszedł do „%s”; wiersz %s usunięto%s.',
                        self::label($extra), $extra->getIssueKey(), TimeFormat::format($extra->getMinutes()), self::label($row),
                        self::label($extra), $inJira ? ' (z Jiry zniknie przy następnej wysyłce)' : '')];
                    $inJira ? $extra->markDeleted() : $day->removeEntry($extra);
                }
            } else {
                $titles ??= $this->days->knownIssueTitles() + $this->settings->issueCache();
                $row = new WorklogEntry($day, $group['key'], 0, $group['description'], ++$position);
                $row->setTrackedSeconds($group['seconds']);
                $row->setTogglIds($group['ids']);
                $row->setStartClock($startClock);
                $row->setIssueTitle($titles[$group['key']] ?? '');
                ++$new;
            }
            $seconds += $group['seconds'];
        }

        // wiersze z Toggla, których wpisów już tam nie ma – zostają bez zmian
        foreach ($existing as $i => $row) {
            if (!in_array($i, $claimed, true) && [] === array_intersect($row->getTogglIds(), $allIds)) {
                $messages[] = ['type' => 'warning', 'message' => sprintf('Wiersz „%s” (%s, %s) nie ma już wpisów w Togglu – czas zostawiono bez zmian.',
                    self::label($row), $row->getIssueKey(), TimeFormat::format($row->getMinutes()))];
            }
        }

        if (null !== $hours && $this->applyHours($day, $hours)) {
            $messages[] = ['type' => 'info', 'message' => sprintf('Godziny obecności z Toggla: %s–%s.', $hours['start'], $hours['end'])];
        }

        if (0 === $seen) {
            array_unshift($messages, ['type' => 'info', 'message' => 'Toggl nie ma wpisów z tego dnia.']);
        } elseif ($new + $updated > 0) {
            $parts = [];
            if ($new > 0) {
                $parts[] = Template::plural($new, 'nowy', 'nowe', 'nowych');
            }
            if ($updated > 0) {
                $parts[] = Template::plural($updated, 'zaktualizowany', 'zaktualizowane', 'zaktualizowanych');
            }
            array_unshift($messages, ['type' => 'success', 'message' => sprintf('Pobrano z Toggla: %s (%s) – razem %s.',
                Template::plural($new + $updated, 'wpis', 'wpisy', 'wpisów'), implode(', ', $parts), TimeFormat::format(intdiv($seconds, 60)))]);
        }

        return $messages;
    }

    /**
     * Obecność z WSZYSTKICH wpisów doby (także pominiętych, np. projekt „Przerwa”): od startu najwcześniejszego
     * do końca najpóźniejszego, pełne minuty w dół. Wpis z działającym stoperem kończy się „teraz”,
     * a koniec po północy przycinamy do 23:59.
     *
     * @param list<array{0: \DateTimeImmutable, 1: array<string, mixed>}> $entries
     *
     * @return array{start: string, end: string}|null
     */
    private function presence(array $entries, \DateTimeImmutable $now, \DateTimeImmutable $dayStart): ?array
    {
        if ([] === $entries) {
            return null;
        }
        $start = $end = null;
        foreach ($entries as [$s, $e]) {
            $duration = (int) ($e['duration'] ?? 0);
            $stop = $duration < 0 ? max($s->getTimestamp(), $now->getTimestamp()) : $s->getTimestamp() + $duration;
            $start = null === $start ? $s->getTimestamp() : min($start, $s->getTimestamp());
            $end = null === $end ? $stop : max($end, $stop);
        }
        $tz = $dayStart->getTimezone();
        $startLocal = (new \DateTimeImmutable('@'.$start))->setTimezone($tz);
        $endLocal = (new \DateTimeImmutable('@'.$end))->setTimezone($tz);
        $endClock = $endLocal->format('Y-m-d') > $dayStart->format('Y-m-d') ? '23:59' : $endLocal->format('H:i');

        return ['start' => $startLocal->format('H:i'), 'end' => $endClock];
    }

    /**
     * Godziny obecności z Toggla dostaje dzień, w którym użytkownik ich nie ustawił: godziny równe tym z poprzedniego
     * importu, domyślne (tak wyglądają dni utworzone przez import) albo puste. Godziny zmienione ręcznie zostają.
     *
     * @param array{start: string, end: string} $hours
     */
    private function applyHours(WorkDay $day, array $hours): bool
    {
        $current = [(string) $day->getStartTime(), (string) $day->getEndTime()];
        $previous = $day->getTogglHours();
        $defaults = $this->defaults->for($day->getDate());
        $untouched = ['', ''] === $current
            || (null !== $previous ? [$previous['start'], $previous['end']] === $current : [(string) $defaults['start'], (string) $defaults['end']] === $current);
        if (!$untouched) {
            return false;
        }
        $day->setStartTime($hours['start']);
        $day->setEndTime($hours['end']);
        $day->setTogglHours($hours);

        return true;
    }

    private static function label(WorklogEntry $row): string
    {
        return '' !== $row->getDescription() ? $row->getDescription() : $row->getIssueKey();
    }

    /**
     * Wpisy Toggla z tej doby złożone w grupy (ticket, opis). Wszystkie zapytania do Toggla kończą się tutaj,
     * zanim cokolwiek zmienimy w dniu.
     *
     * @param list<array{type: string, message: string}> $messages ostrzeżenia
     * @param int $seen ile wpisów Toggla z tej doby (z działającym stoperem włącznie)
     * @param list<int> $allIds ich identyfikatory
     * @param array{start: string, end: string}|null $hours godziny obecności z pierwszego i ostatniego wpisu (null bez wpisów)
     *
     * @return list<array{key: string, description: string, seconds: int, ids: list<int>, clock: string}>
     */
    private function collect(WorkDay $day, \DateTimeImmutable $now, array &$messages, ?int &$seen, ?array &$allIds, ?array &$hours): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $from = new \DateTimeImmutable($day->getDate()->format('Y-m-d').' 00:00:00', $tz);
        $entries = [];
        foreach ($this->toggl->timeEntries($from, $from->modify('+1 day')) as $e) {
            // Toggl bywa niedokładny przy strefach – o dniu decyduje lokalna data startu
            try {
                $start = (new \DateTimeImmutable((string) ($e['start'] ?? '')))->setTimezone($tz);
            } catch (\Exception) {
                continue;
            }
            if ($start->format('Y-m-d') === $from->format('Y-m-d')) {
                $entries[] = [$start, $e];
            }
        }
        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $seen = count($entries);
        $allIds = array_map(static fn (array $e): int => (int) ($e[1]['id'] ?? 0), $entries);
        $hours = $this->presence($entries, $now, $from);

        $projects = null;
        $groups = [];
        $skipped = [];
        foreach ($entries as [$start, $e]) {
            $description = trim((string) ($e['description'] ?? ''));
            $duration = (int) ($e['duration'] ?? 0);
            // ujemny czas = stoper w Togglu nadal działa – doliczamy czas zmierzony do chwili pobrania
            $running = $duration < 0;
            if ($running) {
                $duration = max(0, $now->getTimestamp() - $start->getTimestamp());
            } elseif (0 === $duration) {
                continue;
            }

            $key = Template::extractIssueKey($description);
            if (null === $key) {
                $projectId = (int) ($e['project_id'] ?? 0);
                if ($projectId > 0) {
                    $projects ??= $this->toggl->projects(); // tylko gdy któryś wpis tego potrzebuje, raz na import
                    $key = Template::extractIssueKey($projects[$projectId] ?? '');
                }
                $text = $description; // numer z projektu – opis zostaje cały
            } else {
                // numer na początku opisu nie powtarza się w wierszu; numer w środku zostaje razem z opisem
                $text = str_starts_with(mb_strtoupper(ltrim($description)), $key) ? Template::issueTitleAfterKey($description) : $description;
            }
            if (null === $key) {
                $skipped[] = sprintf('„%s” %s', '' !== $description ? $description : 'bez opisu', TimeFormat::format(max(1, intdiv($duration, 60))));
                continue;
            }

            if ($running) {
                $messages[] = ['type' => 'warning', 'message' => sprintf('Stoper w Togglu nadal działa („%s”) – doliczono %s do tej chwili; po zatrzymaniu pobierz ponownie.',
                    '' !== $description ? $description : 'bez opisu', TimeFormat::format(intdiv($duration, 60)))];
            }

            $id = $key."\0".$text;
            $groups[$id] ??= ['key' => $key, 'description' => $text, 'seconds' => 0, 'ids' => [], 'clock' => $start->format('H:i')]; // wpisy są posortowane po starcie, więc pierwszy w grupie jest najwcześniejszy
            $groups[$id]['seconds'] += $duration;
            $groups[$id]['ids'][] = (int) ($e['id'] ?? 0);
        }

        if ([] !== $skipped) {
            $messages[] = ['type' => 'warning', 'message' => 'Pominięto wpisy bez numeru ticketu (w opisie ani w nazwie projektu): '.implode(', ', $skipped).' – dodaj je ręcznie.'];
        }

        return array_values($groups);
    }
}
