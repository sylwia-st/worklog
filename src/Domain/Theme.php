<?php

namespace App\Domain;

/**
 * Motywy – wbudowane są ciemne, z poświatą; różnią się tłem i parą akcentów (--accent, --accent-2: przyciski, gradienty,
 * poświata). Kolory stanów (ok, warn, err, info) i ticketów są wspólne, żeby „nadgodziny” czy „krócej” wyglądały zawsze
 * tak samo – tylko przy jasnym tle (motyw Własny) przechodzą na wersję dla jasnego tła.
 * Efekty (szkło, krawędzie, gradienty) są w base.html.twig i liczą się z tych zmiennych.
 */
final class Theme
{
    public const DEFAULT = 'aurora';
    public const CUSTOM = 'custom';

    /** Kolory, które ustawia się w motywie Własnym: zmienna CSS → opis (w tej kolejności pola w Ustawieniach). */
    public const VARS = [
        'bg' => 'Tło strony',
        'panel' => 'Tło paneli',
        'ink' => 'Tekst',
        'muted' => 'Tekst pomocniczy',
        'line' => 'Linie i ramki',
        'accent' => 'Akcent (przyciski, linki)',
        'accent-2' => 'Drugi akcent (gradienty, poświata)',
        'accent-ink' => 'Tekst na przyciskach',
    ];

    /** Kolory stanów: na ciemnym i na jasnym tle. */
    public const STATES_DARK = [
        'ok' => '#4ade80', 'ok-bg' => '#0d2618', 'warn' => '#f5b942', 'warn-bg' => '#2a200b',
        'err' => '#ff7b7b', 'err-bg' => '#2e1215', 'info' => '#6aaeff', 'info-bg' => '#0e1c33',
    ];
    public const STATES_LIGHT = [
        'ok' => '#2d794d', 'ok-bg' => '#e7f3ec', 'warn' => '#996000', 'warn-bg' => '#fbf1dc',
        'err' => '#b3261e', 'err-bg' => '#fbe7e5', 'info' => '#3c5a8a', 'info-bg' => '#e8eef8',
    ];

    /**
     * Kolory ticketów na osi dnia – stała kolejność, sprawdzona pod kątem daltonizmu (kolejne pary rozróżnialne);
     * osobne odcienie dla ciemnego i jasnego tła paneli.
     */
    public const SERIES_DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];
    public const SERIES_LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

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

    /** @return array<string, string> id → nazwa (wbudowane i Własny) */
    public static function choices(): array
    {
        return array_map(static fn (array $theme): string => $theme[0], self::BUILT_IN) + [self::CUSTOM => 'Własny'];
    }

    /** @return array<string, array<string, string>> id → kolory wbudowanych motywów (bez kolorów stanów) */
    public static function palettes(): array
    {
        return array_map(static fn (array $theme): array => $theme[1], self::BUILT_IN);
    }

    /** Reguła CSS z kolorami motywu – do <style> w base.html.twig. Nieznany motyw = domyślny. */
    public static function css(string $theme, string $customJson = ''): string
    {
        $colors = self::CUSTOM === $theme ? self::custom($customJson) : (self::palettes()[$theme] ?? self::palettes()[self::DEFAULT]);
        $light = self::isLight($colors['bg']);
        $declarations = ['color-scheme: '.($light ? 'light' : 'dark').';'];
        foreach ($colors + ($light ? self::STATES_LIGHT : self::STATES_DARK) as $name => $value) {
            $declarations[] = sprintf('--%s: %s;', $name, $value);
        }
        foreach (self::isLight($colors['panel']) ? self::SERIES_LIGHT : self::SERIES_DARK as $i => $hex) {
            $declarations[] = sprintf('--s%d: %s;', $i + 1, $hex);
        }

        return ':root { '.implode(' ', $declarations).' }';
    }

    /**
     * Kolory motywu Własnego zapisane w Ustawieniach (JSON). Brakujące albo niepoprawne – z motywu domyślnego.
     *
     * @return array<string, string>
     */
    public static function custom(string $json): array
    {
        $data = json_decode($json, true);
        $out = [];
        foreach (array_keys(self::VARS) as $name) {
            $value = is_array($data) ? ($data[$name] ?? null) : null;
            $out[$name] = is_string($value) && self::isColor($value) ? strtolower($value) : self::palettes()[self::DEFAULT][$name];
        }

        return $out;
    }

    public static function isColor(string $value): bool
    {
        return 1 === preg_match('/^#[0-9a-f]{6}$/i', $value);
    }

    /** Jasne tło (wg WCAG jasność względna ≥ 0,18) → jasne kontrolki przeglądarki i kolory stanów dla jasnego tła. */
    public static function isLight(string $hex): bool
    {
        return self::luminance($hex) >= 0.18;
    }

    /** Jasność względna koloru wg WCAG: 0 = czarny, 1 = biały. */
    public static function luminance(string $hex): float
    {
        $channel = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));

        return 0.2126 * $channel((int) $r) + 0.7152 * $channel((int) $g) + 0.0722 * $channel((int) $b);
    }
}
