<?php

namespace App\Domain;

final class Template
{
    private const DAYS = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];

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
}
