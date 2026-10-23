<?php

namespace App\Domain;

/**
 * Bilans dnia. Podstawą do oceny nadgodzin/krótszego dnia jest czas obecności (od–do minus przerwa),
 * a gdy godzin nie podano – suma czasu zalogowanego w ticketach. Ustawienie „bilans z czasu w ticketach”
 * ($balanceFromTickets) przestawia podstawę zawsze na sumę ticketów; obecność jest wtedy tylko informacją.
 */
final class DaySummary
{
    public const KIND_EMPTY = 'empty';
    public const KIND_OK = 'ok';
    public const KIND_OVER = 'over';
    public const KIND_UNDER = 'under';

    private function __construct(
        public readonly int $loggedMinutes,
        public readonly ?int $presenceMinutes,
        public readonly int $normMinutes,
        public readonly int $toleranceMinutes,
        /** Ile odliczono z czasu obecności: część stałej przerwy (np. obiad) przypadająca na godziny pracy + dodatkowa przerwa. */
        public readonly int $breakMinutes = 0,
        /** Bilans (podstawa, nadgodziny/krócej, mail) liczony z czasu w ticketach, nie z godzin obecności. */
        public readonly bool $balanceFromTickets = false,
    ) {
    }

    /**
     * @param int[]   $entryMinutes
     * @param ?string $fixedBreakFrom stała przerwa, np. "12:00" – odliczana tylko w części przypadającej na godziny pracy
     */
    public static function calculate(
        array $entryMinutes,
        ?string $start,
        ?string $end,
        int $extraBreakMinutes,
        int $normMinutes,
        int $toleranceMinutes,
        ?string $fixedBreakFrom = null,
        ?string $fixedBreakTo = null,
        /** @var list<array{0: int, 1: int}> przerwy podane godzinami, np. 13:30–13:50 (minuty od północy) */
        array $breakRanges = [],
        bool $balanceFromTickets = false,
    ): self {
        $logged = array_sum($entryMinutes);
        $presence = null;
        $deducted = 0;

        $s = null !== $start ? TimeFormat::clockToMinutes($start) : null;
        $e = null !== $end ? TimeFormat::clockToMinutes($end) : null;
        if (null !== $s && null !== $e) {
            $span = $e - $s;
            if ($span < 0) {
                $span += 1440; // praca po północy
            }
            $windows = $breakRanges;
            $bf = null !== $fixedBreakFrom ? TimeFormat::clockToMinutes($fixedBreakFrom) : null;
            $bt = null !== $fixedBreakTo ? TimeFormat::clockToMinutes($fixedBreakTo) : null;
            if (null !== $bf && null !== $bt && $bt > $bf) {
                $windows[] = [$bf, $bt];
            }
            // liczą się tylko przerwy w godzinach pracy; nakładające się przedziały odliczamy raz
            foreach (self::mergeRanges($windows) as [$wf, $wt]) {
                $deducted += max(0, min($s + $span, $wt) - max($s, $wf));
            }
            $deducted += max(0, $extraBreakMinutes);
            $presence = max(0, $span - $deducted);
        }

        return new self($logged, $presence, $normMinutes, max(0, $toleranceMinutes), $deducted, $balanceFromTickets);
    }

    /**
     * Sortuje i scala nakładające się przedziały.
     *
     * @param list<array{0: int, 1: int}> $ranges
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function mergeRanges(array $ranges): array
    {
        $ranges = array_values(array_filter($ranges, static fn (array $r) => $r[1] > $r[0]));
        usort($ranges, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($ranges as $r) {
            $last = count($out) - 1;
            if ($last >= 0 && $r[0] <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $r[1]);
            } else {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * Przerwy dnia, które omija praca w ticketach: stały obiad (chyba że tego dnia była w nim praca)
     * oraz przerwy wpisane godzinami, np. 13:30–13:50. Posortowane i scalone.
     *
     * @param list<array{0: int, 1: int}> $breakRanges
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function breakWindows(array $breakRanges, bool $workedThroughFixedBreak, ?string $fixedBreakFrom, ?string $fixedBreakTo): array
    {
        $bf = null !== $fixedBreakFrom ? TimeFormat::clockToMinutes($fixedBreakFrom) : null;
        $bt = null !== $fixedBreakTo ? TimeFormat::clockToMinutes($fixedBreakTo) : null;
        if (null !== $bf && null !== $bt && $bt > $bf && !$workedThroughFixedBreak) {
            $breakRanges[] = [$bf, $bt];
        }

        return self::mergeRanges($breakRanges);
    }

    /**
     * Ile pracy mieści się między godzinami from i to (minuty od północy) po odjęciu przerw.
     *
     * @param list<array{0: int, 1: int}> $windows posortowane, scalone
     */
    public static function workedBetween(int $from, int $to, array $windows): int
    {
        $worked = $to - $from;
        foreach ($windows as [$wf, $wt]) {
            $worked -= max(0, min($to, $wt) - max($from, $wf));
        }

        return max(0, $worked);
    }

    /**
     * Godzina końca pracy trwającej $minutes od $from, gdy przerwy po drodze się przeskakuje
     * (odwrotność workedBetween). Wynik może przekroczyć 1440 (po północy).
     *
     * @param list<array{0: int, 1: int}> $windows posortowane, scalone
     */
    public static function advance(int $from, int $minutes, array $windows): int
    {
        $cursor = $from;
        foreach ($windows as [$wf, $wt]) {
            if ($wt <= $cursor) {
                continue;
            }
            if ($wf >= $cursor + $minutes) {
                break;
            }
            $minutes -= max(0, $wf - $cursor);
            $cursor = $wt;
        }

        return $cursor + $minutes;
    }

    /** Część przedziału [from, to) (w minutach od północy) pokrywająca się ze stałą przerwą. */
    public static function overlap(int $from, int $to, ?string $breakFrom, ?string $breakTo): int
    {
        $bf = null !== $breakFrom ? TimeFormat::clockToMinutes($breakFrom) : null;
        $bt = null !== $breakTo ? TimeFormat::clockToMinutes($breakTo) : null;
        if (null === $bf || null === $bt || $bt <= $bf) {
            return 0;
        }

        return max(0, min($to, $bt) - max($from, $bf));
    }

    public function basisMinutes(): int
    {
        return $this->balanceFromTickets ? $this->loggedMinutes : ($this->presenceMinutes ?? $this->loggedMinutes);
    }

    /** Bilans wychodzi z godzin obecności (a nie z ticketów – z braku godzin albo z ustawienia). */
    public function basedOnPresence(): bool
    {
        return null !== $this->presenceMinutes && !$this->balanceFromTickets;
    }

    /** Dodatnia = nadgodziny, ujemna = krócej. */
    public function difference(): int
    {
        return $this->basisMinutes() - $this->normMinutes;
    }

    public function kind(): string
    {
        if (0 === $this->loggedMinutes && ($this->balanceFromTickets || null === $this->presenceMinutes)) {
            return self::KIND_EMPTY;
        }
        $diff = $this->difference();
        if ($diff > $this->toleranceMinutes) {
            return self::KIND_OVER;
        }
        if ($diff < -$this->toleranceMinutes) {
            return self::KIND_UNDER;
        }

        return self::KIND_OK;
    }

    /** Różnica: zalogowane − obecność (null gdy brak godzin obecności albo bilans liczony z ticketów – wtedy nie ma czego ostrzegać). */
    public function loggedVsPresence(): ?int
    {
        return null === $this->presenceMinutes || $this->balanceFromTickets ? null : $this->loggedMinutes - $this->presenceMinutes;
    }
}
