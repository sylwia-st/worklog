<?php

namespace App\Domain;

/**
 * Motywy – wszystkie ciemne, z poświatą; różnią się tłem i parą akcentów (--accent, --accent-2: przyciski, gradienty,
 * poświata). Kolory stanów (ok, warn, err, info) są wspólne, żeby „nadgodziny” czy „krócej” wyglądały zawsze tak samo.
 * Efekty (szkło, krawędzie, gradienty) są w base.html.twig i liczą się z tych zmiennych.
 */
final class Theme
{
    public const DEFAULT = 'aurora';

    /** Kolory stanów – wspólne dla wszystkich motywów. */
    private const STATES = [
        'ok' => '#4ade80', 'ok-bg' => '#0d2618', 'warn' => '#f5b942', 'warn-bg' => '#2a200b',
        'err' => '#ff7b7b', 'err-bg' => '#2e1215', 'info' => '#6aaeff', 'info-bg' => '#0e1c33',
    ];

    /** id → [nazwa, kolory]; kontrast tekstu sprawdza tests/domain_test.php */
    public const BUILT_IN = [
        'aurora' => ['Aurora', [
            'bg' => '#0a0b10', 'panel' => '#12141b', 'ink' => '#eceef4', 'muted' => '#8d93a5', 'line' => '#272a36',
            'accent' => '#8b7dff', 'accent-2' => '#6aaeff', 'accent-ink' => '#0c0a1f',
        ]],
        'ocean' => ['Głębia', [
            'bg' => '#050e12', 'panel' => '#0b171c', 'ink' => '#e8f3f5', 'muted' => '#86a0a8', 'line' => '#1d3038',
            'accent' => '#2dd4bf', 'accent-2' => '#38bdf8', 'accent-ink' => '#04201d',
        ]],
        'sunset' => ['Zachód', [
            'bg' => '#100a0b', 'panel' => '#1a1214', 'ink' => '#f6ecec', 'muted' => '#a8918f', 'line' => '#34262a',
            'accent' => '#ff8a57', 'accent-2' => '#f472b6', 'accent-ink' => '#2a0d06',
        ]],
        'neon' => ['Neon', [
            'bg' => '#0c0912', 'panel' => '#16111d', 'ink' => '#f1ecf7', 'muted' => '#9d90ab', 'line' => '#2d2438',
            'accent' => '#f062c0', 'accent-2' => '#a78bfa', 'accent-ink' => '#2a0620',
        ]],
        'mono' => ['Grafit', [
            'bg' => '#09090b', 'panel' => '#131316', 'ink' => '#f4f4f5', 'muted' => '#93939c', 'line' => '#27272c',
            'accent' => '#e4e4e7', 'accent-2' => '#a1a1aa', 'accent-ink' => '#111113',
        ]],
    ];

    /** @return array<string, string> id → nazwa */
    public static function choices(): array
    {
        return array_map(static fn (array $theme): string => $theme[0], self::BUILT_IN);
    }

    /** @return array<string, array<string, string>> id → wszystkie kolory (z kolorami stanów) */
    public static function palettes(): array
    {
        return array_map(static fn (array $theme): array => $theme[1] + self::STATES, self::BUILT_IN);
    }

    /** Reguła CSS z kolorami motywu – do <style> w base.html.twig. Nieznany motyw = domyślny. */
    public static function css(string $theme): string
    {
        $colors = self::palettes()[$theme] ?? self::palettes()[self::DEFAULT];
        $declarations = [];
        foreach ($colors as $name => $value) {
            $declarations[] = sprintf('--%s: %s;', $name, $value);
        }

        return ':root { '.implode(' ', $declarations).' }';
    }
}
