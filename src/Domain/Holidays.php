<?php

namespace App\Domain;

/**
 * Dni ustawowo wolne od pracy w Polsce (ustawa o dniach wolnych od pracy; Wigilia – od 2025 r.).
 * Za święto w sobotę pracodawca wyznacza inny dzień wolny – ten wpisuje się na stronie „Wolne” jako dzień wolny.
 */
final class Holidays
{
    /** @var array<int, array<string, string>> */
    private static array $cache = [];

    /** @return array<string, string> 'Y-m-d' → nazwa, w kolejności dat */
    public static function forYear(int $year): array
    {
        if (isset(self::$cache[$year])) {
            return self::$cache[$year];
        }
        $easter = self::easter($year);
        $days = [
            "$year-01-01" => 'Nowy Rok',
            "$year-01-06" => 'Święto Trzech Króli',
            $easter->format('Y-m-d') => 'Wielkanoc',
            $easter->modify('+1 day')->format('Y-m-d') => 'Poniedziałek Wielkanocny',
            "$year-05-01" => 'Święto Pracy',
            "$year-05-03" => 'Święto Konstytucji 3 Maja',
            $easter->modify('+49 days')->format('Y-m-d') => 'Zielone Świątki',
            $easter->modify('+60 days')->format('Y-m-d') => 'Boże Ciało',
            "$year-08-15" => 'Wniebowzięcie Najświętszej Maryi Panny',
            "$year-11-01" => 'Wszystkich Świętych',
            "$year-11-11" => 'Narodowe Święto Niepodległości',
            "$year-12-25" => 'Boże Narodzenie (pierwszy dzień)',
            "$year-12-26" => 'Boże Narodzenie (drugi dzień)',
        ];
        if ($year >= 2025) {
            $days["$year-12-24"] = 'Wigilia Bożego Narodzenia';
        }
        ksort($days);

        return self::$cache[$year] = $days;
    }

    public static function name(\DateTimeInterface $date): ?string
    {
        return self::forYear((int) $date->format('Y'))[$date->format('Y-m-d')] ?? null;
    }

    /** Dni robocze w zakresie (włącznie): bez weekendów i świąt – tyle dni urlopu zajmuje taki zakres. */
    public static function workingDays(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $count = 0;
        for ($d = $from->setTime(0, 0); $d <= $to; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') < 6 && null === self::name($d)) {
                ++$count;
            }
        }

        return $count;
    }

    /** Wielkanoc w kalendarzu gregoriańskim (algorytm Meeusa/Jonesa/Butchera). */
    public static function easter(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $n = $h + $l - 7 * $m + 114;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, intdiv($n, 31), $n % 31 + 1));
    }
}
