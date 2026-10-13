<?php
// Test serwisów na minimalnych atrapach Doctrine i HttpClient – bez vendor/: php tests/services_test.php

namespace Doctrine\Common\Collections {
    if (!interface_exists(Collection::class)) {
        interface Collection extends \IteratorAggregate {}
        class ArrayCollection implements Collection
        {
            public function __construct(private array $items = []) {}
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
            public function add($e): void { $this->items[] = $e; }
            public function contains($e): bool { return in_array($e, $this->items, true); }
            public function removeElement($e): void { $this->items = array_values(array_filter($this->items, fn ($x) => $x !== $e)); }
            public function filter(\Closure $f): self { return new self(array_values(array_filter($this->items, $f))); }
            public function toArray(): array { return $this->items; }
        }
    }
}

namespace Doctrine\ORM {
    if (!interface_exists(EntityManagerInterface::class)) {
        interface EntityManagerInterface {}
    }
}

namespace Symfony\Contracts\HttpClient {
    if (!interface_exists(HttpClientInterface::class)) {
        interface HttpClientInterface { public function request(string $method, string $url, array $options = []); }
    }
}
namespace Symfony\Contracts\HttpClient\Exception {
    if (!interface_exists(TransportExceptionInterface::class)) {
        interface TransportExceptionInterface extends \Throwable {}
    }
}

namespace {
    use App\Entity\PlannedChange;
    use App\Entity\WorkDay;
    use App\Entity\WorklogEntry;
    use App\Service\JiraClient;
    use App\Service\ReportBuilder;
    use App\Service\Settings;
    use App\Service\WorklogSync;
    use Symfony\Contracts\HttpClient\HttpClientInterface;

    spl_autoload_register(static function (string $class): void {
        $file = __DIR__.'/../src/'.str_replace(['App\\', '\\'], ['', '/'], $class).'.php';
        if (is_file($file)) {
            require $file;
        }
    });
    date_default_timezone_set('Europe/Warsaw');

    // Settings bez bazy: nadpisujemy all() przez refleksję na polu cache
    $settings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
    $values = Settings::defaultsFor('f');
    $values['jira_url'] = 'https://firma.atlassian.net/';
    $values['jira_email'] = 'ja@firma.pl';
    $values['jira_token'] = 'tok';
    $values['mail_over_to'] = 'szef@firma.pl, hr@firma.pl';
    $values['mail_plan_to'] = 'szef@firma.pl';
    $values['signature'] = 'Anna';
    (new ReflectionProperty(Settings::class, 'cache'))->setValue($settings, $values);

    final class FakeHttp implements HttpClientInterface
    {
        public array $calls = [];
        public array $fail = [];
        private int $nextId = 100;
        public function request(string $method, string $url, array $options = [])
        {
            $this->calls[] = [$method, $url, $options['json'] ?? null, isset($options['auth_basic'])];
            $status = 200;
            $body = '';
            foreach ($this->fail as $needle => $code) {
                if (str_contains($url, $needle)) {
                    $status = $code;
                    $body = json_encode(['errorMessages' => ['Issue does not exist']]);
                }
            }
            if (200 === $status && 'POST' === $method) {
                $body = json_encode(['id' => (string) $this->nextId++]);
            }
            return new class($status, $body) {
                public function __construct(private int $s, private string $b) {}
                public function getStatusCode(): int { return $this->s; }
                public function getContent(bool $throw = true): string { return $this->b; }
            };
        }
    }

    $fail = 0;
    function check(string $name, mixed $actual, mixed $expected): void
    {
        global $fail;
        if ($actual !== $expected) {
            ++$fail;
            echo "FAIL $name:\n  oczekiwano ".var_export($expected, true)."\n  jest       ".var_export($actual, true)."\n";
        }
    }
    function setId(object $o, int $id): void { (new ReflectionProperty($o, 'id'))->setValue($o, $id); }

    $reports = new ReportBuilder($settings);
    $day = new WorkDay(new DateTimeImmutable('2026-10-13'));
    $day->setStartTime('08:00');
    $day->setEndTime('17:00');
    $a = new WorklogEntry($day, 'ABC-1', 240, 'Logowanie SSO', 0);
    $b = new WorklogEntry($day, 'ABC-2', 180, 'Code review', 1);
    $c = new WorklogEntry($day, 'ABC-1', 120, 'Testy SSO', 2);
    setId($a, 1); setId($b, 2); setId($c, 3);

    check('slack', $reports->slackText($day), "Raport z 13.10.2026 (wtorek):\n• ABC-1 – 6h – Logowanie SSO; Testy SSO\n• ABC-2 – 3h – Code review\nRazem: 9h");

    $mail = $reports->presenceMail($day);
    check('mail over', $mail?->subject, 'Nadgodziny – 13.10.2026');
    check('mail to', $mail?->to, ['szef@firma.pl', 'hr@firma.pl']);
    check('mail body', $mail?->body, "Dzień dobry,\n\nw dniu 13.10.2026 (wtorek) pracowałam dłużej o 1h – w godzinach 08:00–17:00.\n\nPozdrawiam\nAnna");

    $plan = new PlannedChange(new DateTimeImmutable('2026-10-15'), PlannedChange::TYPE_LATE, '10:00', null, '');
    $pm = $reports->plannedMail($plan);
    // planowane zmiany: domyślnie temat i treść to sama data – dla każdego rodzaju
    foreach ([[PlannedChange::TYPE_LATE, '10:00', null], [PlannedChange::TYPE_EARLY, null, '14:00'], [PlannedChange::TYPE_SHORT, '09:00', '13:00']] as [$type, $from, $to]) {
        $pd = $reports->plannedMail(new PlannedChange(new DateTimeImmutable('2026-10-15'), $type, $from, $to, 'lekarz'));
        check('plan '.$type.' – sama data', [$pd->subject, $pd->body, $pd->to], ['15.10.2026', '15.10.2026', ['szef@firma.pl']]);
    }
    // własny szablon ze zdaniem {opis} nadal działa
    $cache = new ReflectionProperty(Settings::class, 'cache');
    $saved = $cache->getValue($settings);
    $cache->setValue($settings, ['mail_plan_subject' => '{typ} – {data}', 'mail_plan_body' => "w dniu {data} ({dzien}) {opis}.\n{powod_linia}"] + $saved);
    $pm = $reports->plannedMail(new PlannedChange(new DateTimeImmutable('2026-10-15'), PlannedChange::TYPE_EARLY, null, '14:00', 'lekarz'));
    check('plan własny szablon', [$pm->subject, $pm->body], ['Wcześniejsze wyjście – 15.10.2026', "w dniu 15.10.2026 (czwartek) wyjdę wcześniej, ok. godz. 14:00.\nPowód: lekarz"]);
    $cache->setValue($settings, $saved);
    check('notice', round($reports->hoursOfNotice($plan, new DateTimeImmutable('2026-10-14 08:00')), 1), 24.0);

    // --- synchronizacja ---
    $http = new FakeHttp();
    $sync = new WorklogSync(new JiraClient($http, $settings), $settings);

    check('statusy przed', $sync->statuses($day), [1 => 'new', 2 => 'new', 3 => 'new']);
    $res = $sync->sync($day);
    check('3 POST-y', array_map(fn ($c) => [$c[0], $c[1]], $http->calls), [
        ['POST', 'https://firma.atlassian.net/rest/api/2/issue/ABC-1/worklog'],
        ['POST', 'https://firma.atlassian.net/rest/api/2/issue/ABC-2/worklog'],
        ['POST', 'https://firma.atlassian.net/rest/api/2/issue/ABC-1/worklog'],
    ]);
    check('started kolejne', array_map(fn ($c) => $c[2]['started'], $http->calls), [
        // 4h od 8:00 kończy się o 12:00 → kolejny wpis startuje po obiedzie
        '2026-10-13T08:00:00.000+0200', '2026-10-13T13:00:00.000+0200', '2026-10-13T16:00:00.000+0200',
    ]);
    check('seconds', $http->calls[1][2]['timeSpentSeconds'], 10800);
    check('comment', $http->calls[1][2]['comment'], 'Code review');
    check('basic auth cloud', $http->calls[0][3], true);
    check('statusy po', $sync->statuses($day), [1 => 'synced', 2 => 'synced', 3 => 'synced']);
    check('fully synced', $sync->isFullySynced($day), true);

    // drugi sync bez zmian = nic nie wysyła
    $http->calls = [];
    $res = $sync->sync($day);
    check('bez zmian', [count($http->calls), $res[0]['type']], [0, 'info']);

    // zmiana czasu wpisu 2 → aktualizacja jego i przesunięcie startu wpisu 3
    $b->update('ABC-2', 120, 'Code review', 1);
    check('statusy po edycji', $sync->statuses($day), [1 => 'synced', 2 => 'changed', 3 => 'changed']);
    $http->calls = [];
    $sync->sync($day);
    check('PUT x2', array_map(fn ($c) => $c[0].' '.basename($c[1]), $http->calls), ['PUT 101', 'PUT 102']);

    // zmiana ticketu → DELETE ze starego, POST do nowego
    $b->update('XYZ-9', 120, 'Code review', 1);
    $http->calls = [];
    $sync->sync($day);
    check('przeniesienie', array_map(fn ($c) => $c[0].' '.$c[1], $http->calls), [
        'DELETE https://firma.atlassian.net/rest/api/2/issue/ABC-2/worklog/101',
        'POST https://firma.atlassian.net/rest/api/2/issue/XYZ-9/worklog',
    ]);

    // usunięcie zsynchronizowanego wpisu → DELETE i zniknięcie z dnia
    $c->markDeleted();
    check('nie domknięte', $sync->isFullySynced($day), false);
    $http->calls = [];
    $sync->sync($day);
    check('delete', $http->calls[0][0].' '.basename($http->calls[0][1]), 'DELETE 102');
    check('usunięty z dnia', count($day->getEntries()->toArray()), 2);

    // błąd Jiry
    $d = new WorklogEntry($day, 'NOPE-1', 30, '', 3);
    setId($d, 4);
    $http->fail = ['NOPE-1' => 404];
    $res = $sync->sync($day);
    check('błąd 404', str_contains(end($res)['message'], 'Nie znaleziono (404)') && str_contains(end($res)['message'], 'Issue does not exist'), true);
    check('status error', $sync->statuses($day)[4], 'error');
    check('bez komentarza gdy pusty', array_key_exists('comment', end($http->calls)[2]), false);

    // wpis przechodzący przez obiad: 11:00 + 2h → następny o 14:00
    $day2 = new WorkDay(new DateTimeImmutable('2026-10-14'));
    $day2->setStartTime('11:00');
    $x = new WorklogEntry($day2, 'ABC-5', 120, '', 0);
    $y = new WorklogEntry($day2, 'ABC-6', 30, '', 1);
    $http->calls = []; $http->fail = [];
    $sync->sync($day2);
    check('przez obiad', array_map(fn ($c) => substr($c[2]['started'], 11, 5), $http->calls), ['11:00', '14:00']);
    // awaria w porze obiadu: obiad nieodliczany, przerwa wzięta później na raty (20m + 40m = 1h)
    $day3 = new WorkDay(new DateTimeImmutable('2026-10-15'));
    $day3->setStartTime('08:00');
    $day3->setEndTime('16:00');
    $day3->setBreakMinutes(App\Domain\TimeFormat::parseBreak('20m + 40m'));
    $day3->setWorkedThroughFixedBreak(true);
    $z1 = new WorklogEntry($day3, 'OPS-1', 240, 'Awaria', 0);
    $z2 = new WorklogEntry($day3, 'OPS-1', 180, 'Awaria c.d.', 1);
    $sum3 = $reports->summary($day3);
    check('awaria: obecność', [$sum3->presenceMinutes, $sum3->breakMinutes, $sum3->kind()], [420, 60, 'ok']);
    $http->calls = [];
    $sync->sync($day3);
    check('awaria: worklog o 12:00', array_map(fn ($c) => substr($c[2]['started'], 11, 5), $http->calls), ['08:00', '12:00']);
    $day3->setBreakMinutes(0);
    check('awaria bez przerwy = nadgodzina', [$reports->summary($day3)->presenceMinutes, $reports->summary($day3)->kind()], [480, 'over']);

    // awaria + przerwa wzięta godzinami: 13:30–13:50 i 15:00–15:40
    $day4 = new WorkDay(new DateTimeImmutable('2026-10-16'));
    $day4->setStartTime('08:00');
    $day4->setEndTime('16:00');
    $day4->setWorkedThroughFixedBreak(true);
    $day4->setBreakText('13:30-13:50, 15:00-15:40');
    $w1 = new WorklogEntry($day4, 'OPS-2', 330, 'Awaria', 0);   // 8:00 → 13:30
    $w2 = new WorklogEntry($day4, 'OPS-2', 60, 'Awaria c.d.', 1); // 13:50 → 14:50
    $w3 = new WorklogEntry($day4, 'ABC-9', 30, 'Review', 2);      // 14:50 → 15:00, przerwa 15:00–15:40, → 16:00
    $w4 = new WorklogEntry($day4, 'ABC-9', 15, 'Review c.d.', 3); // 16:00
    $sum4 = $reports->summary($day4);
    check('godziny przerw: obecność', [$sum4->presenceMinutes, $sum4->breakMinutes, $sum4->kind()], [420, 60, 'ok']);
    $http->calls = [];
    $sync->sync($day4);
    check('godziny przerw: worklogi omijają przerwy', array_map(fn ($c) => substr($c[2]['started'], 11, 5), $http->calls), ['08:00', '13:50', '14:50', '16:00']);

    // --- stoper: pełne minuty, bez zaokrąglania, sekundy przechodzą na kolejną sesję ---
    $tday = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $t = new WorklogEntry($tday, 'TIM-1', 0, '', 0);
    $t->startTimer(new DateTimeImmutable('2026-10-20 09:00:00'));
    $t->stopTimer(new DateTimeImmutable('2026-10-20 09:12:30'));   // 12m 30s
    check('stoper sesja 1', [$t->getMinutes(), $t->getCarrySeconds()], [12, 30]);
    $t->startTimer(new DateTimeImmutable('2026-10-20 10:00:00'));
    $t->stopTimer(new DateTimeImmutable('2026-10-20 10:17:30'));   // 17m 30s → razem 30m 0s
    check('stoper 12.5 + 17.5 = 30', [$t->getMinutes(), $t->getCarrySeconds()], [30, 0]);
    $t->startTimer(new DateTimeImmutable('2026-10-20 11:00:00'));
    $t->stopTimer(new DateTimeImmutable('2026-10-20 11:36:59'));   // 36m 59s – bez zaokrąglania w górę
    check('stoper bez zaokrąglania', [$t->getMinutes(), $t->getCarrySeconds()], [66, 59]);
    check('stoper zatrzymany', $t->isTimerRunning(), false);
    check('stop bez startu', $t->stopTimer(new DateTimeImmutable()), 0);
    $t->startTimer(new DateTimeImmutable('2026-10-20 12:00:00'));
    $t->startTimer(new DateTimeImmutable('2026-10-20 12:30:00'));  // ponowny start nie resetuje
    check('start idempotentny', $t->getTimerStartedAt()->format('H:i'), '12:00');
    check('czas na żywo', $t->liveSeconds(new DateTimeImmutable('2026-10-20 12:00:02')), 66 * 60 + 59 + 2);
    $t->stopTimer(new DateTimeImmutable('2026-10-20 11:00:00'));   // godzina przed startem → nic nie dolicza
    check('stop przed startem', $t->getMinutes(), 66);
    $t->update('TIM-1', 90, '', 0);
    check('ręczna zmiana czasu kasuje resztę sekund', [$t->getMinutes(), $t->getCarrySeconds()], [90, 0]);
    $t->update('TIM-1', 90, 'opis', 0);
    $t->addTrackedSeconds(45);
    $t->update('TIM-1', 90, 'opis 2', 0);
    check('zmiana opisu nie kasuje sekund', $t->getCarrySeconds(), 45);

    // podejrzany stoper
    check('stale: inny dzień', str_contains((string) App\Service\TimerService::staleReason(new DateTimeImmutable('2026-10-19 15:00'), new DateTimeImmutable('2026-10-20 08:10')), 'innego dnia'), true);
    check('stale: >6h', str_contains((string) App\Service\TimerService::staleReason(new DateTimeImmutable('2026-10-20 08:00'), new DateTimeImmutable('2026-10-20 14:30')), 'ponad 6h'), true);
    check('stale: normalnie', App\Service\TimerService::staleReason(new DateTimeImmutable('2026-10-20 08:00'), new DateTimeImmutable('2026-10-20 11:00')), null);
    check('podpowiedź: koniec dnia startu', App\Service\TimerService::suggestedStop(new DateTimeImmutable('2026-10-19 15:00'), new DateTimeImmutable('2026-10-20 08:10'), '16:00')->format('Y-m-d H:i'), '2026-10-19 16:00');
    check('podpowiedź: po końcu dnia = start', App\Service\TimerService::suggestedStop(new DateTimeImmutable('2026-10-19 17:20'), new DateTimeImmutable('2026-10-20 08:10'), '16:00')->format('Y-m-d H:i'), '2026-10-19 17:20');
    check('podpowiedź: nie w przyszłości', App\Service\TimerService::suggestedStop(new DateTimeImmutable('2026-10-20 08:00'), new DateTimeImmutable('2026-10-20 14:30'), '16:00')->format('H:i'), '14:30');

    // wpis bez czasu (pod stoper) nie idzie do Jiry i nie blokuje „wszystko wysłane”
    $eday = new WorkDay(new DateTimeImmutable('2026-10-21'));
    $e1 = new WorklogEntry($eday, 'ABC-1', 60, 'x', 0); setId($e1, 51);
    $e0 = new WorklogEntry($eday, 'ABC-2', 0, '', 1); setId($e0, 52);
    $http->calls = []; $http->fail = [];
    $res = $sync->sync($eday);
    check('pusty wpis pominięty', [count($http->calls), $sync->statuses($eday)], [1, [51 => 'synced', 52 => 'empty']]);
    check('pusty nie blokuje', $sync->isFullySynced($eday), true);
    check('komunikat o pominięciu', in_array('warning', array_column($res, 'type'), true), true);
    check('pusty nie trafia na Slacka', str_contains($reports->slackText($eday), 'ABC-2'), false);

    check('norma 7h domyślnie', Settings::DEFAULTS['norm_minutes'], '420');
    check('tolerancja 0 domyślnie', Settings::DEFAULTS['tolerance_minutes'], '0');

    // --- forma w tekstach ---
    check('forma domyślnie neutralna', [Settings::DEFAULTS['forma'], Settings::DEFAULTS['mail_over_body']], ['n', Settings::GENDERED['mail_over_body']['n']]);
    check('mail w formie męskiej', str_contains(Settings::defaultsFor('m')['mail_under_body'], 'pracowałem krócej o {roznica}'), true);
    check('mail w formie neutralnej', str_contains(Settings::defaultsFor('n')['mail_over_body'], 'mój czas pracy był dłuższy o {roznica}'), true);
    check('wszystkie formy mają warianty', array_map(static fn (array $v) => array_keys($v), Settings::GENDERED), ['mail_over_body' => ['f', 'm', 'n'], 'mail_under_body' => ['f', 'm', 'n']]);
    foreach (['f' => 'Pracowałam', 'm' => 'Pracowałem', 'n' => 'Praca'] as $f => $expected) {
        (new ReflectionProperty(Settings::class, 'cache'))->setValue($settings, Settings::defaultsFor($f));
        check('form() dla '.$f, $settings->form('Pracowałam', 'Pracowałem', 'Praca'), $expected);
    }

    echo $fail ? "\n$fail błędów\n" : "OK – wszystkie testy serwisów przeszły\n";
    exit($fail ? 1 : 0);
}
