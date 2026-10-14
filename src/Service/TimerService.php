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

    /**
     * Włącza stoper od podanej godziny (domyślnie teraz) – np. gdy praca zaczęła się wcześniej, a ▶ zostało kliknięte później.
     * Poprzedni stoper zatrzymuje się o tej samej godzinie, więc czas się nie dubluje.
     *
     * @throws \InvalidArgumentException godzina w przyszłości, przed zatrzymaniem poprzedniego stopera albo nie późniejsza niż start działającego
     */
    public function start(WorklogEntry $entry, ?\DateTimeImmutable $at = null, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        if (null !== $at && $at > $now) {
            throw new \InvalidArgumentException('Stoper nie może zacząć się w przyszłości.');
        }
        // czas zmierzony wcześniej stoperem nie może się nakładać z nowym
        $last = null !== $at ? $this->entries->findLastStopped() : null;
        if (null !== $last && $at < $last->getTimerStoppedAt()) {
            throw new \InvalidArgumentException(sprintf('Stoper %s był włączony do %s – nowy może zacząć się najwcześniej o %s, żeby czas się nie nakładał.', $last->getIssueKey(), self::clock($last->getTimerStoppedAt()), self::ceilMinute($last->getTimerStoppedAt())->format('H:i')));
        }
        $running = array_filter($this->entries->findRunning(), static fn (WorklogEntry $other) => $other !== $entry);
        foreach ($running as $other) {
            // poprzedni stoper kończy się o godzinie startu nowego – koniec musi być po jego starcie
            if (null !== $at && $at <= $other->getTimerStartedAt()) {
                throw new \InvalidArgumentException(sprintf('Stoper %s działa od %s – nowy musi zacząć się później.', $other->getIssueKey(), self::clock($other->getTimerStartedAt())));
            }
        }
        $at ??= $now;
        foreach ($running as $other) {
            $other->stopTimer($at);
        }
        $entry->startTimer($at);
        $this->em->flush();
    }

    /**
     * Zatrzymuje działający stoper o podanej godzinie (domyślnie teraz).
     *
     * @throws \InvalidArgumentException podana godzina nie jest późniejsza niż start stopera
     */
    public function stop(?\DateTimeImmutable $at = null, \DateTimeImmutable $now = new \DateTimeImmutable()): ?WorklogEntry
    {
        $running = $this->entries->findRunning();
        foreach ($running as $entry) {
            if (null !== $at && $at <= $entry->getTimerStartedAt()) {
                throw new \InvalidArgumentException(sprintf('Godzina zatrzymania musi być późniejsza niż start stopera (%s).', self::clock($entry->getTimerStartedAt())));
            }
        }
        $stopped = null;
        foreach ($running as $entry) {
            $when = $at ?? $now;
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
            'minStop' => self::earliestStop($started)->format('Y-m-d\TH:i'),
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
        if ($candidate <= $started) {
            // koniec musi być po starcie: najbliższa pełna minuta po nim
            $candidate = self::earliestStop($started);
        }

        return $candidate > $now ? $now : $candidate;
    }

    /** Najwcześniejsza godzina zatrzymania do wyboru w polu (co do minuty): pełna minuta po starcie. */
    public static function earliestStop(\DateTimeImmutable $started): \DateTimeImmutable
    {
        return $started->setTime((int) $started->format('H'), (int) $started->format('i'))->modify('+1 minute');
    }

    /** Zaokrągla w górę do pełnej minuty (godziny wybiera się w polach co do minuty). */
    private static function ceilMinute(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return '00' === $at->format('s') ? $at : self::earliestStop($at);
    }

    private static function clock(\DateTimeImmutable $at): string
    {
        return $at->format('s') === '00' ? $at->format('d.m H:i') : $at->format('d.m H:i:s');
    }
}
