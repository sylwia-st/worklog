<?php

namespace App\Service;

use App\Domain\DaySummary;
use App\Domain\TimeFormat;
use App\Entity\WorkDay;
use App\Entity\WorklogEntry;

/**
 * Wysyła wpisy dnia do Jiry: tworzy nowe worklogi, aktualizuje zmienione, kasuje usunięte.
 * Wpisy dostają kolejne godziny rozpoczęcia (od początku dnia), żeby nie nakładały się w Tempo/kalendarzu.
 */
final class WorklogSync
{
    public const STATUS_NEW = 'new';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_CHANGED = 'changed';
    public const STATUS_ERROR = 'error';
    /** Wpis bez czasu (np. dopiero założony pod stoper) – nie jest wysyłany. */
    public const STATUS_EMPTY = 'empty';

    public function __construct(
        private readonly JiraClient $jira,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return array<int, array{type: string, message: string}> komunikaty do wyświetlenia
     */
    public function sync(WorkDay $day): array
    {
        $results = [];
        $plan = $this->plan($day);

        foreach ($day->getPendingDeletions() as $entry) {
            try {
                if ($entry->isInJira()) {
                    $this->jira->deleteWorklog((string) $entry->getJiraIssueKey(), (string) $entry->getJiraWorklogId());
                    $results[] = ['type' => 'success', 'message' => sprintf('%s: usunięto worklog z Jiry.', $entry->getJiraIssueKey())];
                }
                $day->removeEntry($entry);
            } catch (JiraException $e) {
                $entry->setJiraError($e->getMessage());
                $results[] = ['type' => 'error', 'message' => sprintf('%s: nie udało się usunąć – %s', $entry->getJiraIssueKey(), $e->getMessage())];
            }
        }

        foreach ($day->getActiveEntries() as $entry) {
            $p = $plan[spl_object_id($entry)];
            $key = $entry->getIssueKey();
            $label = sprintf('%s (%s)', $key, TimeFormat::format($entry->getMinutes()));

            if (0 === $entry->getMinutes()) {
                $results[] = ['type' => 'warning', 'message' => $key.': pominięto – wpis nie ma jeszcze czasu.'];
                continue;
            }
            if ($entry->isTimerRunning()) {
                $results[] = ['type' => 'warning', 'message' => $label.': stoper nadal działa – wysłano czas zmierzony do chwili ostatniego zatrzymania.'];
            }

            try {
                if (!$entry->isInJira()) {
                    $id = $this->jira->addWorklog($key, $p['started'], $entry->getMinutes() * 60, $entry->getDescription());
                    $entry->markSynced($id, $p['hash']);
                    $results[] = ['type' => 'success', 'message' => $label.': dodano worklog.'];
                } elseif ($entry->getJiraIssueKey() !== $key) {
                    $this->jira->deleteWorklog((string) $entry->getJiraIssueKey(), (string) $entry->getJiraWorklogId());
                    $entry->markRemovedFromJira();
                    $id = $this->jira->addWorklog($key, $p['started'], $entry->getMinutes() * 60, $entry->getDescription());
                    $entry->markSynced($id, $p['hash']);
                    $results[] = ['type' => 'success', 'message' => $label.': przeniesiono worklog do innego ticketu.'];
                } elseif ($entry->getJiraSyncHash() !== $p['hash']) {
                    $this->jira->updateWorklog($key, (string) $entry->getJiraWorklogId(), $p['started'], $entry->getMinutes() * 60, $entry->getDescription());
                    $entry->markSynced((string) $entry->getJiraWorklogId(), $p['hash']);
                    $results[] = ['type' => 'success', 'message' => $label.': zaktualizowano worklog.'];
                }
            } catch (JiraException $e) {
                $entry->setJiraError($e->getMessage());
                $results[] = ['type' => 'error', 'message' => $label.': '.$e->getMessage()];
            }
        }

        if ([] === $results) {
            $results[] = ['type' => 'info', 'message' => 'Wszystko było już w Jirze – nic do wysłania.'];
        }

        return $results;
    }

    /** @return array<int, string> id wpisu → status */
    public function statuses(WorkDay $day): array
    {
        $plan = $this->plan($day);
        $out = [];
        foreach ($day->getActiveEntries() as $entry) {
            $out[(int) $entry->getId()] = match (true) {
                0 === $entry->getMinutes() && !$entry->isInJira() => self::STATUS_EMPTY,
                null !== $entry->getJiraError() => self::STATUS_ERROR,
                !$entry->isInJira() => self::STATUS_NEW,
                $entry->getJiraIssueKey() !== $entry->getIssueKey(),
                $entry->getJiraSyncHash() !== $plan[spl_object_id($entry)]['hash'] => self::STATUS_CHANGED,
                default => self::STATUS_SYNCED,
            };
        }

        return $out;
    }

    public function isFullySynced(WorkDay $day): bool
    {
        if ([] !== $day->getPendingDeletions()) {
            return false;
        }
        foreach ($this->statuses($day) as $status) {
            if (self::STATUS_SYNCED !== $status && self::STATUS_EMPTY !== $status) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array{started: \DateTimeImmutable, hash: string}> */
    private function plan(WorkDay $day): array
    {
        $startClock = $day->getStartTime() ?? $this->settings->get('default_start');
        $startMinutes = TimeFormat::clockToMinutes($startClock) ?? 8 * 60;
        // Żaden worklog nie zaczyna się w przerwie, a wpis, na który przerwa wypada, „przeskakuje” ją –
        // kolejne wpisy startują później.
        $breaks = $this->dayBreakWindows($day);
        // Wpisy z ręcznie podaną godziną rozpoczęcia startują dokładnie o niej (także w przerwie),
        // a pozostałe omijają zajęty przez nie czas tak jak przerwy.
        $windows = $breaks;
        $midnight = $day->getDate()->setTime(0, 0);
        $plan = [];
        foreach ($day->getActiveEntries() as $entry) {
            $fixed = null !== $entry->getStartClock() ? TimeFormat::clockToMinutes($entry->getStartClock()) : null;
            if (null !== $fixed) {
                $plan[spl_object_id($entry)] = self::planned($entry, $midnight->modify(sprintf('+%d minutes', $fixed)));
                if ($entry->getMinutes() > 0) {
                    $windows[] = [$fixed, DaySummary::advance($fixed, $entry->getMinutes(), $breaks)];
                }
            }
        }
        $windows = DaySummary::mergeRanges($windows);

        $cursor = $startMinutes;
        foreach ($day->getActiveEntries() as $entry) {
            /** @var WorklogEntry $entry */
            if (isset($plan[spl_object_id($entry)])) {
                continue;
            }
            $cursor = self::skipBreaks($cursor, $windows);
            $started = $midnight->modify(sprintf('+%d minutes', $cursor));
            $plan[spl_object_id($entry)] = self::planned($entry, $started);

            // przesuwamy kursor o czas pracy, przeskakując przerwy, które wypadają w trakcie wpisu
            $cursor = DaySummary::advance($cursor, $entry->getMinutes(), $windows);
        }

        return $plan;
    }

    /**
     * Przerwy dnia, które omijają worklogi: stały obiad (chyba że tego dnia była w nim praca)
     * oraz przerwy wpisane godzinami, np. 13:30–13:50.
     *
     * @param list<array{0: int, 1: int}> $breakRanges
     *
     * @return list<array{0: int, 1: int}>
     */
    public function breakWindows(array $breakRanges, bool $workedThroughFixedBreak): array
    {
        return DaySummary::breakWindows($breakRanges, $workedThroughFixedBreak,
            $this->settings->get('lunch_from') ?: null, $this->settings->get('lunch_to') ?: null);
    }

    /** @return list<array{0: int, 1: int}> */
    public function dayBreakWindows(WorkDay $day): array
    {
        return $this->breakWindows($day->getBreakRanges(), $day->hasWorkedThroughFixedBreak());
    }

    /** Godzina zakończenia wpisu z ręczną godziną rozpoczęcia – przerwy po drodze ją przesuwają; null bez godziny lub czasu. */
    public function endClock(WorklogEntry $entry): ?string
    {
        $start = null !== $entry->getStartClock() ? TimeFormat::clockToMinutes($entry->getStartClock()) : null;
        if (null === $start || 0 === $entry->getMinutes()) {
            return null;
        }

        return TimeFormat::minutesToClock(DaySummary::advance($start, $entry->getMinutes(), $this->dayBreakWindows($entry->getDay())));
    }

    /** @return array{started: \DateTimeImmutable, hash: string} */
    private static function planned(WorklogEntry $entry, \DateTimeImmutable $started): array
    {
        return [
            'started' => $started,
            'hash' => sha1(implode("\0", [$entry->getIssueKey(), $entry->getMinutes(), $entry->getDescription(), $started->format(\DATE_ATOM)])),
        ];
    }

    /** @param list<array{0: int, 1: int}> $windows posortowane, scalone */
    private static function skipBreaks(int $cursor, array $windows): int
    {
        foreach ($windows as [$wf, $wt]) {
            if ($cursor >= $wf && $cursor < $wt) {
                $cursor = $wt;
            }
        }

        return $cursor;
    }
}
