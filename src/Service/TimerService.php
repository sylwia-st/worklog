<?php

namespace App\Service;

use App\Domain\TimeFormat;
use App\Entity\WorklogEntry;
use App\Repository\WorklogEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stoper per wpis. Stan trzymany wyłącznie w bazie, więc przeżywa zamknięcie przeglądarki i restart komputera.
 * Naraz działa najwyżej jeden stoper – włączenie kolejnego zatrzymuje poprzedni.
 */
final class TimerService
{
    /** Po tylu godzinach ciągłej pracy stopera pytamy, czy na pewno nie został włączony przez pomyłkę. */
    public const SUSPICIOUS_HOURS = 6;

    public function __construct(
        private readonly WorklogEntryRepository $entries,
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
    ) {
    }

    public function running(): ?WorklogEntry
    {
        return $this->entries->findRunning()[0] ?? null;
    }

    public function start(WorklogEntry $entry, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        foreach ($this->entries->findRunning() as $other) {
            if ($other !== $entry) {
                $other->stopTimer($now);
            }
        }
        $entry->startTimer($now);
        $this->em->flush();
    }

    /** Zatrzymuje działający stoper o podanej godzinie (domyślnie teraz). */
    public function stop(?\DateTimeImmutable $at = null, \DateTimeImmutable $now = new \DateTimeImmutable()): ?WorklogEntry
    {
        $stopped = null;
        foreach ($this->entries->findRunning() as $entry) {
            $when = $at ?? $now;
            $started = $entry->getTimerStartedAt();
            if ($when < $started) {
                $when = $started;
            }
            if ($when > $now) {
                $when = $now;
            }
            $entry->stopTimer($when);
            $stopped = $entry;
        }
        $this->em->flush();

        return $stopped;
    }

    /** @return array<string, mixed> stan dla przeglądarki */
    public function state(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $entry = $this->running();
        if (null === $entry) {
            return ['running' => false];
        }
        $started = $entry->getTimerStartedAt();
        $endClock = $entry->getDay()->getEndTime() ?? $this->settings->get('default_end');

        return [
            'running' => true,
            'entryId' => $entry->getId(),
            'issue' => $entry->getIssueKey(),
            'date' => $entry->getDay()->getDate()->format('Y-m-d'),
            'startedAt' => $started->format(\DATE_ATOM),
            'startedLabel' => $started->format('d.m H:i'),
            'baseSeconds' => $entry->getMinutes() * 60 + $entry->getCarrySeconds(),
            'elapsedSeconds' => max(0, $now->getTimestamp() - $started->getTimestamp()),
            'stale' => self::staleReason($started, $now),
            'suggestedStop' => self::suggestedStop($started, $now, $endClock)->format('Y-m-d\TH:i'),
        ];
    }

    /** Powód, by zapytać o godzinę zatrzymania – albo null, gdy stoper wygląda na normalnie działający. */
    public static function staleReason(\DateTimeImmutable $started, \DateTimeImmutable $now): ?string
    {
        if ($started->format('Y-m-d') !== $now->format('Y-m-d')) {
            return sprintf('Stoper działa od %s – czyli od innego dnia (np. komputer był uśpiony albo został na noc).', $started->format('d.m.Y H:i'));
        }
        if ($now->getTimestamp() - $started->getTimestamp() > self::SUSPICIOUS_HOURS * 3600) {
            return sprintf('Stoper działa bez przerwy od %s (ponad %dh).', $started->format('H:i'), self::SUSPICIOUS_HOURS);
        }

        return null;
    }

    /** Podpowiedź godziny zatrzymania: koniec dnia pracy w dniu startu, ale nie wcześniej niż start i nie później niż teraz. */
    public static function suggestedStop(\DateTimeImmutable $started, \DateTimeImmutable $now, string $endClock): \DateTimeImmutable
    {
        $end = TimeFormat::clockToMinutes($endClock) ?? 16 * 60;
        $candidate = $started->setTime(intdiv($end, 60), $end % 60);
        if ($candidate < $started) {
            $candidate = $started;
        }

        return $candidate > $now ? $now : $candidate;
    }
}
