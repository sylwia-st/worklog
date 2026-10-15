<?php

namespace App\Domain;

final class Template
{
    private const DAYS = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
    private const MONTHS_OF = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    private const MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    /**
     * Podstawia {zmienne}. Nieznane zostawia bez zmian, żeby literówka w szablonie była widoczna w podglądzie.
     *
     * @param array<string, string> $vars
     */
    public static function render(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{'.$k.'}'] = $v;
        }
        $out = strtr(str_replace("\r\n", "\n", $template), $map);

        // puste zmienne (np. {powod_linia}) zostawiają po sobie nadmiar pustych linii
        return trim(preg_replace("/\n{3,}/", "\n\n", $out));
    }

    public static function dayName(\DateTimeInterface $date): string
    {
        return self::DAYS[(int) $date->format('w')];
    }

    /** „dziś”, „jutro”, dalej „piątek 16.10” */
    public static function relativeDay(\DateTimeInterface $date, \DateTimeInterface $today): string
    {
        $days = (int) \DateTimeImmutable::createFromInterface($today)->setTime(0, 0)
            ->diff(\DateTimeImmutable::createFromInterface($date)->setTime(0, 0))->format('%r%a');

        return match ($days) {
            0 => 'dziś',
            1 => 'jutro',
            default => self::dayName($date).' '.$date->format('d.m'),
        };
    }

    /** „14 października”, z rokiem, gdy inny niż bieżący: „30 grudnia 2025” */
    public static function longDate(\DateTimeInterface $date, \DateTimeInterface $today): string
    {
        $out = $date->format('j').' '.self::MONTHS_OF[(int) $date->format('n') - 1];

        return $date->format('Y') === $today->format('Y') ? $out : $out.' '.$date->format('Y');
    }

    /** „październik 2026” */
    public static function monthName(\DateTimeInterface $date): string
    {
        return self::MONTHS[(int) $date->format('n') - 1].' '.$date->format('Y');
    }

    /** Liczba z rzeczownikiem w dobrej formie: plural(1, 'dzień', 'dni', 'dni') → „1 dzień”, 22 → „22 dni”. */
    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $form = match (true) {
            1 === $n => $one,
            in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) => $few,
            default => $many,
        };

        return $n.' '.$form;
    }

    /** @return string[] */
    public static function parseAddresses(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $a) => false !== filter_var($a, FILTER_VALIDATE_EMAIL))));
    }

    /** @return string[] adresy z listy, które nie wyglądają na e-mail */
    public static function invalidAddresses(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, static fn (string $a) => false === filter_var($a, FILTER_VALIDATE_EMAIL)));
    }

    public static function extractIssueKey(string $raw): ?string
    {
        return preg_match('/\b([A-Z][A-Z0-9_]+-\d+)\b/', mb_strtoupper(trim($raw)), $m) ? $m[1] : null;
    }

    /** Tekst wpisany za numerem ticketu – „ABC-123 Produkcja nie działa” → „Produkcja nie działa”. */
    public static function issueTitleAfterKey(string $raw): string
    {
        return preg_match('/^\s*[A-Za-z][A-Za-z0-9_]+-\d+\b\s*[-–—:|]?\s*(.*)$/su', $raw, $m) ? trim($m[1]) : '';
    }
}
