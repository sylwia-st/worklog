<?php
// Szybkie testy logiki domenowej – nie wymagają frameworka: php tests/domain_test.php

spl_autoload_register(static function (string $class): void {
    $file = __DIR__.'/../src/'.str_replace(['App\\', '\\'], ['', '/'], $class).'.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Domain\DaySummary;
use App\Domain\Holidays;
use App\Domain\MailDraft;
use App\Domain\ReminderSchedule;
use App\Domain\Template;
use App\Domain\Theme;
use App\Domain\TimeFormat;

$fail = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $fail;
    if ($actual !== $expected) {
        ++$fail;
        echo "FAIL $name: oczekiwano ".var_export($expected, true).', jest '.var_export($actual, true)."\n";
    }
}

foreach ([
    '1h 30m' => 90, '1h30' => 90, '1h30m' => 90, '1.5' => 90, '1,5h' => 90, '90m' => 90, '90 min' => 90,
    '1:30' => 90, '2h' => 120, '2 godz' => 120, '0.25' => 15, '15m' => 15, ' 8 ' => 480,
    '' => null, 'abc' => null, '0' => null, '25h' => null, 'h' => null, '-1h' => null,
] as $in => $exp) {
    check("parse('$in')", TimeFormat::parse((string) $in), $exp);
}
check('format 90', TimeFormat::format(90), '1h 30m');
check('format 45', TimeFormat::format(45), '45m');
check('format 120', TimeFormat::format(120), '2h');
check('format -75', TimeFormat::format(-75), '-1h 15m');
check('clock 8:05', TimeFormat::normalizeClock('8:05'), '08:05');
check('clock 8.05', TimeFormat::normalizeClock('8.05'), '08:05');
check('clock 24:00', TimeFormat::normalizeClock('24:00'), null);

$s = DaySummary::calculate([240, 300], '08:00', '17:30', 30, 480, 10);
check('presence', $s->presenceMinutes, 540);
check('diff', $s->difference(), 60);
check('kind over', $s->kind(), DaySummary::KIND_OVER);
check('logged vs presence', $s->loggedVsPresence(), 0);

$s = DaySummary::calculate([420], null, null, 0, 480, 10);
check('basis logged', $s->basisMinutes(), 420);
check('kind under', $s->kind(), DaySummary::KIND_UNDER);

$s = DaySummary::calculate([475], '08:00', '15:55', 0, 480, 10);
check('kind ok w tolerancji', $s->kind(), DaySummary::KIND_OK);
check('kind empty', DaySummary::calculate([], null, null, 0, 480, 10)->kind(), DaySummary::KIND_EMPTY);
check('po północy', DaySummary::calculate([], '22:00', '06:00', 0, 480, 0)->presenceMinutes, 480);

foreach (['' => 0, '30' => 30, '1h' => 60, '20m + 40m' => 60, '15, 15, 30' => 60, '10+5' => 15, 'abc' => null, '20m + x' => null] as $in => $exp) {
    check("parseBreak('$in')", TimeFormat::parseBreak((string) $in), $exp);
}

check('spec godziny', TimeFormat::parseBreakSpec('13:30-13:50, 15:00–15:40'), ['minutes' => 0, 'ranges' => [[810, 830], [900, 940]]]);
check('spec mieszany', TimeFormat::parseBreakSpec('13.30-13.50 + 40m'), ['minutes' => 40, 'ranges' => [[810, 830]]]);
check('spec suma', TimeFormat::parseBreak('13:30-13:50, 15:00-15:40'), 60);
check('spec odwrotny', TimeFormat::parseBreakSpec('14:00-13:00'), null);
check('merge', DaySummary::mergeRanges([[780, 800], [720, 780], [900, 910]]), [[720, 800], [900, 910]]);
$s = DaySummary::calculate([], '08:00', '16:00', 0, 420, 10, null, null, [[810, 830], [900, 940]]);
check('przerwy godzinami', [$s->presenceMinutes, $s->breakMinutes], [420, 60]);
$s = DaySummary::calculate([], '08:00', '16:00', 0, 420, 10, '12:00', '13:00', [[750, 790]]);
check('przerwa częściowo w obiedzie liczona raz', $s->breakMinutes, 70);
$s = DaySummary::calculate([], '08:00', '15:00', 0, 420, 10, null, null, [[930, 960]]);
check('przerwa po wyjściu nie odlicza się', $s->breakMinutes, 0);

// stała przerwa obiadowa 12–13
$s = DaySummary::calculate([], '08:00', '16:00', 0, 420, 10, '12:00', '13:00');
check('obiad pełny dzień', [$s->presenceMinutes, $s->breakMinutes, $s->kind()], [420, 60, DaySummary::KIND_OK]);
$s = DaySummary::calculate([], '08:00', '11:30', 0, 420, 10, '12:00', '13:00');
check('wyjście przed obiadem', [$s->presenceMinutes, $s->breakMinutes], [210, 0]);
$s = DaySummary::calculate([], '12:30', '18:00', 0, 420, 10, '12:00', '13:00');
check('przyjście w trakcie obiadu', [$s->presenceMinutes, $s->breakMinutes], [300, 30]);
$s = DaySummary::calculate([], '08:00', '17:00', 15, 420, 10, '12:00', '13:00');
check('obiad + dodatkowa', [$s->presenceMinutes, $s->breakMinutes, $s->difference()], [465, 75, 45]);
check('bez stałej przerwy', DaySummary::calculate([], '08:00', '16:00', 0, 420, 10, null, null)->presenceMinutes, 480);

check('render', Template::render("A {x}\n\n\n\n{y}B {nieznana}", ['x' => '1', 'y' => '']), "A 1\n\nB {nieznana}");
check('day', Template::dayName(new DateTimeImmutable('2026-10-13')), 'wtorek');
check('addr', Template::parseAddresses('a@x.pl, b@y.pl; zly  c@z.pl a@x.pl'), ['a@x.pl', 'b@y.pl', 'c@z.pl']);
check('addr invalid', Template::invalidAddresses('a@x.pl, zly'), ['zly']);
check('key', Template::extractIssueKey('abc-123 – Poprawka logowania'), 'ABC-123');
check('key2', Template::extractIssueKey('PROJ2_X-7'), 'PROJ2_X-7');
check('key none', Template::extractIssueKey('123'), null);

$m = new MailDraft(['a@x.pl', 'b@x.pl'], ['c@x.pl'], 'Nadgodziny – 13.10', "Linia 1\nZażółć & ?");
check('mailto', $m->mailtoUrl(), 'mailto:a@x.pl,b@x.pl?cc=c@x.pl&subject=Nadgodziny%20%E2%80%93%2013.10&body=Linia%201%0D%0AZa%C5%BC%C3%B3%C5%82%C4%87%20%26%20%3F');

// przypomnienie: raz dziennie, o godzinie z Ustawień albo przy pierwszym sprawdzeniu po niej
$tue = static fn (string $hm) => new DateTimeImmutable('2026-10-13 '.$hm); // wtorek
check('przypomnienie: przed godziną', ReminderSchedule::isDue('15:45', '', $tue('15:44')), false);
check('przypomnienie: o godzinie', ReminderSchedule::isDue('15:45', '', $tue('15:45')), true);
check('przypomnienie: nadrobione po włączeniu komputera', ReminderSchedule::isDue('15:45', '2026-10-12 15:45', $tue('18:10')), true);
check('przypomnienie: już było dziś', ReminderSchedule::isDue('15:45', ReminderSchedule::stamp('15:45', $tue('15:45')), $tue('15:50')), false);
check('przypomnienie: zmiana godziny uzbraja od nowa', ReminderSchedule::isDue('16:30', '2026-10-13 15:45', $tue('16:30')), true);
check('przypomnienie: wyłączone', ReminderSchedule::isDue('', '', $tue('16:00')), false);
check('przypomnienie: weekend', ReminderSchedule::isDue('15:45', '', new DateTimeImmutable('2026-10-17 16:00')), false);
check('przypomnienie: 8:05 = 08:05', ReminderSchedule::stamp('8:05', $tue('09:00')), '2026-10-13 08:05');
check('przypomnienie: zakres timera', [ReminderSchedule::inTimerWindow('06:00'), ReminderSchedule::inTimerWindow('21:59'), ReminderSchedule::inTimerWindow('22:00'), ReminderSchedule::inTimerWindow('05:59')], [true, true, false, false]);

// --- święta i dni robocze ---
foreach (['1999-04-04', '2000-04-23', '2024-03-31', '2025-04-20', '2026-04-05', '2027-03-28', '2030-04-21', '2038-04-25'] as $e) {
    check("Wielkanoc $e", Holidays::easter((int) substr($e, 0, 4))->format('Y-m-d'), $e);
}
$h26 = Holidays::forYear(2026);
check('święta 2026: liczba (z Wigilią)', count($h26), 14);
check('święta 2026: ruchome', [$h26['2026-04-06'] ?? null, $h26['2026-05-24'] ?? null, $h26['2026-06-04'] ?? null], ['Poniedziałek Wielkanocny', 'Zielone Świątki', 'Boże Ciało']);
check('święta 2026: w kolejności dat', array_keys($h26) === array_values(array_unique(array_keys($h26))) && array_keys($h26)[0] === '2026-01-01' && array_key_last($h26) === '2026-12-26', true);
check('Wigilia wolna dopiero od 2025', [isset(Holidays::forYear(2024)['2024-12-24']), isset(Holidays::forYear(2025)['2025-12-24'])], [false, true]);
check('święto: nazwa', [Holidays::name(new DateTimeImmutable('2026-11-11')), Holidays::name(new DateTimeImmutable('2026-11-12'))], ['Narodowe Święto Niepodległości', null]);
check('dni robocze: tydzień z 11 listopada', Holidays::workingDays(new DateTimeImmutable('2026-11-09'), new DateTimeImmutable('2026-11-13')), 4);
check('dni robocze: przełom roku (Wigilia, święta, Nowy Rok)', Holidays::workingDays(new DateTimeImmutable('2026-12-21'), new DateTimeImmutable('2027-01-03')), 7);
check('dni robocze: sam weekend', Holidays::workingDays(new DateTimeImmutable('2026-10-17'), new DateTimeImmutable('2026-10-18')), 0);
check('odmiana', array_map(static fn (int $n) => Template::plural($n, 'dzień roboczy', 'dni robocze', 'dni roboczych'), [0, 1, 2, 5, 12, 22, 25]),
    ['0 dni roboczych', '1 dzień roboczy', '2 dni robocze', '5 dni roboczych', '12 dni roboczych', '22 dni robocze', '25 dni roboczych']);

// --- motywy ---
check('motywy: lista', array_keys(Theme::choices()), ['auto', 'light', 'dark', 'sea', 'sepia', 'night', 'contrast', 'custom']);
$contrast = static function (string $a, string $b): float {
    [$hi, $lo] = [max(Theme::luminance($a), Theme::luminance($b)), min(Theme::luminance($a), Theme::luminance($b))];

    return ($hi + 0.05) / ($lo + 0.05);
};
foreach (Theme::palettes() as $id => $c) {
    $keys = array_keys($c);
    sort($keys);
    $expected = array_keys(Theme::VARS);
    sort($expected);
    check("motyw $id: komplet kolorów", $keys, $expected);
    check("motyw $id: same #rrggbb", array_filter($c, static fn (string $v) => !Theme::isColor($v)), []);
    // czytelność wg WCAG: tekst 4.5:1 (główny 7:1), komunikaty co najmniej jak elementy interfejsu 3:1
    $low = [];
    foreach ([['ink', 'bg', 7], ['ink', 'panel', 7], ['muted', 'panel', 4.5], ['muted', 'bg', 4.5], ['accent', 'panel', 4.5], ['accent-ink', 'accent', 4.5],
              ['ok', 'ok-bg', 3], ['warn', 'warn-bg', 3], ['err', 'err-bg', 3], ['info', 'info-bg', 3]] as [$fg, $bg, $min]) {
        if ($contrast($c[$fg], $c[$bg]) < $min) {
            $low[] = sprintf('%s/%s %.2f', $fg, $bg, $contrast($c[$fg], $c[$bg]));
        }
    }
    check("motyw $id: kontrast", $low, []);
}
$auto = Theme::css('auto', '');
check('css auto: jasny + ciemny wg systemu', [str_contains($auto, '--bg: #f6f5f2;'), str_contains($auto, '@media (prefers-color-scheme: dark) { :root { --bg: #161716;')], [true, true]);
$night = Theme::css('night', '');
check('css nocny: kolory i ciemne kontrolki', [str_contains($night, '--bg: #0f1724;'), str_contains($night, 'color-scheme: dark;'), str_contains($night, '@media')], [true, true, false]);
check('css sepia: jasne kontrolki', str_contains(Theme::css('sepia', ''), 'color-scheme: light;'), true);
check('css nieznany motyw = automatyczny', Theme::css('xyz', ''), $auto);
$own = Theme::css('custom', (string) json_encode(['bg' => '#000000', 'ink' => '#FFFFFF']));
check('css własny: kolory z ustawień, reszta z jasnego', [str_contains($own, '--bg: #000000;'), str_contains($own, '--ink: #ffffff;'), str_contains($own, '--accent: #2f5d50;'), str_contains($own, 'color-scheme: dark;')], [true, true, true, true]);
check('własny: śmieci zamiast koloru → jasny', Theme::custom((string) json_encode(['bg' => 'red; } body { display:none', 'line' => '#12345'])), Theme::palettes()['light']);
check('własny: pusty zapis → jasny', Theme::custom(''), Theme::palettes()['light']);
check('kolor: format', [Theme::isColor('#a1B2c3'), Theme::isColor('#123'), Theme::isColor('#12345g'), Theme::isColor('a1b2c3')], [true, false, false, false]);

echo $fail ? "\n$fail błędów\n" : "OK – wszystkie testy przeszły\n";
exit($fail ? 1 : 0);
