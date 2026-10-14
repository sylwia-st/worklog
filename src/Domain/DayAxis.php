<?php

namespace App\Domain;

/**
 * Oś dnia na stronie dnia – dwa paski:
 *  - „W pracy”: godziny od–do na skali zegara, z zakreskowanymi przerwami (obiad, przerwy podane godzinami),
 *  - „W ticketach”: czas per ticket jeden za drugim, z kreską normy i czasu obecności.
 * Wszystkie pozycje w procentach szerokości paska.
 */
final class DayAxis
{
    /**
     * @param list<array{0: string, 1: int}>   $tickets ticket → minuty, w kolejności wpisów
     * @param list<array{0: int, 1: int}>      $breaks  przerwy w minutach od północy (obiad, przedziały z pola przerwy)
     *
     * @return array{
     *     track: ?array{ticks: list<array{label: string, left: float}>, presence: array{left: float, width: float, label: string}, breaks: list<array{left: float, width: float, label: string}>},
     *     stack: array{segments: list<array{key: string, minutes: int, width: float}>, markers: list<array{left: float, label: string, kind: string}>, scale: int}
     * }
     */
    public static function build(?string $start, ?string $end, array $breaks, array $tickets, int $norm, ?int $presence): array
    {
        return ['track' => self::track($start, $end, $breaks, $presence), 'stack' => self::stack($tickets, $norm, $presence)];
    }

    private static function track(?string $start, ?string $end, array $breaks, ?int $presence): ?array
    {
        $s = null !== $start ? TimeFormat::clockToMinutes($start) : null;
        $e = null !== $end ? TimeFormat::clockToMinutes($end) : null;
        if (null === $s || null === $e || $e <= $s) {
            return null; // brak godzin albo praca po północy – pasek zegara nie ma sensu
        }
        // skala: pełne godziny, co najmniej 7:00–17:00, z godziną zapasu wokół pracy
        $from = min(7 * 60, intdiv(max(0, $s - 30), 60) * 60);
        $to = max(17 * 60, (intdiv(min(1440, $e + 30) + 59, 60)) * 60);
        $pct = static fn (int $m) => round(($m - $from) / ($to - $from) * 100, 2);

        $ticks = [];
        $step = $to - $from > 12 * 60 ? 180 : 120;
        for ($m = $from; $m < $to; $m += $step) {
            $ticks[] = ['label' => intdiv($m, 60).':00', 'left' => $pct($m)];
        }
        $out = [];
        foreach ($breaks as [$bf, $bt]) {
            $bf = max($bf, $s);
            $bt = min($bt, $e);
            if ($bt > $bf) {
                $out[] = ['left' => $pct($bf), 'width' => round($pct($bt) - $pct($bf), 2), 'label' => TimeFormat::minutesToClock($bf).'–'.TimeFormat::minutesToClock($bt)];
            }
        }

        return [
            'ticks' => $ticks,
            'presence' => ['left' => $pct($s), 'width' => round($pct($e) - $pct($s), 2), 'label' => null !== $presence ? TimeFormat::format($presence) : ''],
            'breaks' => $out,
        ];
    }

    private static function stack(array $tickets, int $norm, ?int $presence): array
    {
        $logged = array_sum(array_column($tickets, 1));
        // skala do pełnej godziny ponad największą z wartości, żeby kreski normy i obecności nie stały na krawędzi
        $scale = max(60, (intdiv(max($norm, $presence ?? 0, $logged) + 30 + 59, 60)) * 60);
        $segments = [];
        foreach ($tickets as [$key, $minutes]) {
            if ($minutes > 0) {
                $segments[] = ['key' => $key, 'minutes' => $minutes, 'width' => round($minutes / $scale * 100, 2)];
            }
        }
        $markers = [];
        if ($norm > 0) {
            $markers[] = ['left' => round($norm / $scale * 100, 2), 'label' => 'norma '.TimeFormat::format($norm), 'kind' => 'norm'];
        }
        if (null !== $presence && $presence !== $norm) {
            $markers[] = ['left' => round($presence / $scale * 100, 2), 'label' => 'obecność '.TimeFormat::format($presence), 'kind' => 'presence'];
        }

        return ['segments' => $segments, 'markers' => $markers, 'scale' => $scale];
    }
}
