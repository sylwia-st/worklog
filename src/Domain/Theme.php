<?php

namespace App\Domain;

/**
 * Motywy kolorystyczne – wartości zmiennych CSS, z których korzysta cały wygląd (base.html.twig).
 * „auto” to jasny albo ciemny motyw zależnie od ustawień systemu, „custom” – kolory wybrane w Ustawieniach.
 */
final class Theme
{
    public const AUTO = 'auto';
    public const CUSTOM = 'custom';

    /** Zmienna CSS → opis w Ustawieniach (w tej kolejności pola motywu własnego). */
    public const VARS = [
        'bg' => 'Tło strony',
        'panel' => 'Tło paneli',
        'ink' => 'Tekst',
        'muted' => 'Tekst pomocniczy',
        'line' => 'Linie i ramki',
        'accent' => 'Akcent (przyciski, linki)',
        'accent-ink' => 'Tekst na akcencie',
        'ok' => 'Sukces',
        'ok-bg' => 'Tło sukcesu',
        'warn' => 'Ostrzeżenie',
        'warn-bg' => 'Tło ostrzeżenia',
        'err' => 'Błąd',
        'err-bg' => 'Tło błędu',
        'info' => 'Informacja',
        'info-bg' => 'Tło informacji',
    ];

    /** Wbudowane motywy: id → [nazwa, kolory]. */
    public const BUILT_IN = [
        'light' => ['Jasny', [
            'bg' => '#f6f5f2', 'panel' => '#ffffff', 'ink' => '#1d1d1b', 'muted' => '#6b6a66', 'line' => '#e2e0da',
            'accent' => '#2f5d50', 'accent-ink' => '#ffffff', 'ok' => '#2e7d4f', 'ok-bg' => '#e7f3ec', 'warn' => '#a86a00',
            'warn-bg' => '#fbf1dc', 'err' => '#b3261e', 'err-bg' => '#fbe7e5', 'info' => '#3c5a8a', 'info-bg' => '#e8eef8',
        ]],
        'dark' => ['Ciemny', [
            'bg' => '#161716', 'panel' => '#1f201f', 'ink' => '#ecebe7', 'muted' => '#a09f9a', 'line' => '#343532',
            'accent' => '#7fb8a5', 'accent-ink' => '#10201b', 'ok' => '#7fcf9d', 'ok-bg' => '#1f3227', 'warn' => '#e8b552',
            'warn-bg' => '#3a2f17', 'err' => '#f08b84', 'err-bg' => '#3d2120', 'info' => '#9db7e3', 'info-bg' => '#1f2a3b',
        ]],
        'sea' => ['Morski', [
            'bg' => '#f2f6f9', 'panel' => '#ffffff', 'ink' => '#14212b', 'muted' => '#5d6b77', 'line' => '#d9e2ea',
            'accent' => '#1f5f8b', 'accent-ink' => '#ffffff', 'ok' => '#2e7d4f', 'ok-bg' => '#e6f3ec', 'warn' => '#9a6200',
            'warn-bg' => '#fbf0d9', 'err' => '#b3261e', 'err-bg' => '#fbe7e5', 'info' => '#1f5f8b', 'info-bg' => '#e3eef7',
        ]],
        'sepia' => ['Sepia', [
            'bg' => '#f4ecd8', 'panel' => '#fbf6e9', 'ink' => '#3b2f22', 'muted' => '#6e5e4a', 'line' => '#e2d6bc',
            'accent' => '#8a4b2a', 'accent-ink' => '#ffffff', 'ok' => '#4a7326', 'ok-bg' => '#e8eed6', 'warn' => '#8f5500',
            'warn-bg' => '#f5e3c0', 'err' => '#a8321f', 'err-bg' => '#f6dcd3', 'info' => '#4f5f80', 'info-bg' => '#e6e4dc',
        ]],
        'night' => ['Nocny', [
            'bg' => '#0f1724', 'panel' => '#172131', 'ink' => '#e6edf5', 'muted' => '#9aa8b9', 'line' => '#2a3648',
            'accent' => '#6fa8ff', 'accent-ink' => '#0b1220', 'ok' => '#6fd39a', 'ok-bg' => '#15301f', 'warn' => '#f0c062',
            'warn-bg' => '#33290f', 'err' => '#ff8f87', 'err-bg' => '#3a1c1d', 'info' => '#8fb8ff', 'info-bg' => '#16243b',
        ]],
        'contrast' => ['Wysoki kontrast', [
            'bg' => '#ffffff', 'panel' => '#ffffff', 'ink' => '#000000', 'muted' => '#333333', 'line' => '#000000',
            'accent' => '#0033cc', 'accent-ink' => '#ffffff', 'ok' => '#006b2d', 'ok-bg' => '#e2f5e9', 'warn' => '#7a4a00',
            'warn-bg' => '#fff2cc', 'err' => '#b00020', 'err-bg' => '#ffe0e3', 'info' => '#0033cc', 'info-bg' => '#e0e8ff',
        ]],
    ];

    /** @return array<string, string> id → nazwa, do listy w Ustawieniach */
    public static function choices(): array
    {
        return [self::AUTO => 'Automatyczny – jasny albo ciemny jak w systemie']
            + array_map(static fn (array $theme): string => $theme[0], self::BUILT_IN)
            + [self::CUSTOM => 'Własny'];
    }

    /** @return array<string, array<string, string>> id → kolory wbudowanych motywów (podgląd w Ustawieniach) */
    public static function palettes(): array
    {
        return array_map(static fn (array $theme): array => $theme[1], self::BUILT_IN);
    }

    /** Reguły CSS z kolorami motywu – do <style> w base.html.twig. Nieznany motyw = automatyczny. */
    public static function css(string $theme, string $customJson): string
    {
        if (self::CUSTOM === $theme) {
            return self::rule(self::custom($customJson));
        }
        if (isset(self::BUILT_IN[$theme])) {
            return self::rule(self::BUILT_IN[$theme][1]);
        }

        return self::rule(self::BUILT_IN['light'][1])."\n@media (prefers-color-scheme: dark) { ".self::rule(self::BUILT_IN['dark'][1]).' }';
    }

    /**
     * Kolory motywu własnego zapisane w Ustawieniach (JSON). Brakujące albo niepoprawne – z motywu jasnego.
     *
     * @return array<string, string>
     */
    public static function custom(string $json): array
    {
        $data = json_decode($json, true);
        $out = [];
        foreach (array_keys(self::VARS) as $name) {
            $value = is_array($data) ? ($data[$name] ?? null) : null;
            $out[$name] = is_string($value) && self::isColor($value) ? strtolower($value) : self::BUILT_IN['light'][1][$name];
        }

        return $out;
    }

    public static function isColor(string $value): bool
    {
        return 1 === preg_match('/^#[0-9a-f]{6}$/i', $value);
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

    /** @param array<string, string> $colors */
    private static function rule(array $colors): string
    {
        $declarations = [];
        foreach ($colors as $name => $value) {
            $declarations[] = sprintf('--%s: %s;', $name, $value);
        }
        // ciemne tło → ciemne także kontrolki przeglądarki (kalendarz w polu daty, paski przewijania)
        $declarations[] = 'color-scheme: '.(self::luminance($colors['bg']) < 0.18 ? 'dark' : 'light').';';

        return ':root { '.implode(' ', $declarations).' }';
    }
}
