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

namespace Doctrine\Bundle\DoctrineBundle\Repository {
    if (!class_exists(ServiceEntityRepository::class)) {
        class ServiceEntityRepository {}
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

    // kalendarz dni wolnych na atrapie repozytorium – nieobecności ustawia się w $absenceRepo->items
    $absenceRepo = new class extends App\Repository\AbsenceRepository {
        public array $items = [];
        public function __construct() {}
        public function findOverlapping(\DateTimeImmutable $from, \DateTimeImmutable $to): array
        {
            return array_values(array_filter($this->items, fn ($a) => $a->getDateFrom() <= $to && $a->getDateTo() >= $from));
        }
    };
    $reports = new ReportBuilder($settings, new App\Service\WorkCalendar($absenceRepo));
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

    // --- dni wolne: święto > nieobecność > weekend; norma 0 ---
    $absenceRepo->items = [new App\Entity\Absence(new DateTimeImmutable('2026-11-09'), new DateTimeImmutable('2026-11-13'), 'urlop', 'Zakopane')];
    $cal = new App\Service\WorkCalendar($absenceRepo);
    $kind = static fn (string $d) => ($cal->dayOff(new DateTimeImmutable($d)) ?? ['kind' => '-', 'label' => '-'])['kind'].': '.($cal->dayOff(new DateTimeImmutable($d)) ?? ['label' => '-'])['label'];
    check('kalendarz: urlop', $kind('2026-11-10'), 'absence: Urlop – Zakopane');
    check('kalendarz: święto w trakcie urlopu', $kind('2026-11-11'), 'holiday: Narodowe Święto Niepodległości');
    check('kalendarz: weekend', $kind('2026-11-14'), 'weekend: Sobota');
    check('kalendarz: dzień roboczy', $cal->dayOff(new DateTimeImmutable('2026-11-16')), null);
    check('kalendarz: zakres – tylko dni wolne', array_keys($cal->daysOff(new DateTimeImmutable('2026-11-12'), new DateTimeImmutable('2026-11-16'))), ['2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15']);
    $holiday = new WorkDay(new DateTimeImmutable('2026-11-11'));
    new WorklogEntry($holiday, 'OPS-9', 120, 'Awaria', 0);
    $hs = $reports->summary($holiday);
    check('święto: norma 0, praca = nadgodziny', [$hs->normMinutes, $hs->difference(), $hs->kind(), null !== $reports->presenceMail($holiday)], [0, 120, 'over', true]);
    $normal = new WorkDay(new DateTimeImmutable('2026-11-16'));
    new WorklogEntry($normal, 'OPS-9', 120, 'Awaria', 0);
    check('dzień roboczy: norma z ustawień', [$reports->summary($normal)->normMinutes, $reports->summary($normal)->kind()], [420, 'under']);
    $absenceRepo->items = [];

    // --- stoper od podanej godziny ---
    $runningRepo = new class extends App\Repository\WorklogEntryRepository {
        public array $entries = [];
        public function __construct() {}
        public function findRunning(): array { return array_values(array_filter($this->entries, fn ($e) => $e->isTimerRunning())); }
        public function findLastStopped(): ?WorklogEntry
        {
            $stopped = array_filter($this->entries, fn ($e) => !$e->isDeleted() && null !== $e->getTimerStoppedAt());
            usort($stopped, fn ($a, $b) => $b->getTimerStoppedAt() <=> $a->getTimerStoppedAt());

            return $stopped[0] ?? null;
        }
    };
    $flushEm = new class implements Doctrine\ORM\EntityManagerInterface { public function flush(): void {} };
    $timer = new App\Service\TimerService($runningRepo, $flushEm, $settings);
    $sday = new WorkDay(new DateTimeImmutable('2026-10-20'));
    $s1 = new WorklogEntry($sday, 'STA-1', 0, '', 0);
    $s2 = new WorklogEntry($sday, 'STA-2', 0, '', 1);
    $runningRepo->entries = [$s1, $s2];
    $now = new DateTimeImmutable('2026-10-20 11:00:00');
    $timer->start($s1, new DateTimeImmutable('2026-10-20 09:15'), $now);
    check('start od godziny', $s1->getTimerStartedAt()->format('H:i'), '09:15');
    check('start od godziny: czas od tej godziny', $s1->liveSeconds($now), 105 * 60);
    $timer->start($s2, new DateTimeImmutable('2026-10-20 10:30'), $now);
    check('przełączenie od godziny: poprzedni zatrzymany o tej godzinie', [$s1->isTimerRunning(), $s1->getMinutes()], [false, 75]);
    check('przełączenie od godziny: nowy działa od niej', $s2->getTimerStartedAt()->format('H:i'), '10:30');
    $err = null;
    try { $timer->start($s1, new DateTimeImmutable('2026-10-20 10:00'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('start przed startem działającego stopera – odmowa', [str_contains((string) $err, '10:30'), $s2->isTimerRunning(), $s1->isTimerRunning()], [true, true, false]);
    $err = null;
    try { $timer->start($s1, new DateTimeImmutable('2026-10-20 10:30'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('start równo ze startem działającego stopera – odmowa (koniec = początek)', [$err, $s2->isTimerRunning()], ['Stoper STA-2 działa od 20.10 10:30 – nowy musi zacząć się później.', true]);
    $err = null;
    try { $timer->start($s1, new DateTimeImmutable('2026-10-20 11:05'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('start w przyszłości – odmowa', [$err, $s1->isTimerRunning()], ['Stoper nie może zacząć się w przyszłości.', false]);
    $timer->start($s1, null, $now);
    check('start bez godziny = teraz', [$s1->getTimerStartedAt()->format('H:i'), $s2->getMinutes()], ['11:00', 30]);

    // --- koniec stopera musi być późniejszy niż start ---
    foreach (['2026-10-20 10:59' => 'przed startem', '2026-10-20 11:00' => 'równo ze startem'] as $at => $label) {
        $err = null;
        try { $timer->stop(new DateTimeImmutable($at), new DateTimeImmutable('2026-10-20 12:00')); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
        check("stop $label – odmowa", [$err, $s1->isTimerRunning(), $s1->getMinutes()], ['Godzina zatrzymania musi być późniejsza niż start stopera (20.10 11:00).', true, 75]);
    }
    $s3 = new WorklogEntry($sday, 'STA-3', 0, '', 2);
    $s3->startTimer(new DateTimeImmutable('2026-10-20 11:00:40'));
    $runningRepo->entries = [$s3];
    $err = null;
    try { $timer->stop(new DateTimeImmutable('2026-10-20 11:00'), new DateTimeImmutable('2026-10-20 12:00')); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('stop w tej samej minucie co start z sekundami – odmowa', $err, 'Godzina zatrzymania musi być późniejsza niż start stopera (20.10 11:00:40).');
    $timer->stop(new DateTimeImmutable('2026-10-20 11:30'), new DateTimeImmutable('2026-10-20 12:00'));
    check('stop po starcie – dolicza', [$s3->isTimerRunning(), $s3->getMinutes()], [false, 29]);
    $s3->startTimer(new DateTimeImmutable('2026-10-20 12:00'));
    $timer->stop(null, new DateTimeImmutable('2026-10-20 12:00'));
    check('stop „teraz” w sekundzie startu – bez błędu', $s3->isTimerRunning(), false);

    // --- nowy stoper nie może nachodzić na czas zmierzony wcześniej ---
    $o1 = new WorklogEntry($sday, 'OVL-1', 0, '', 3);
    $o2 = new WorklogEntry($sday, 'OVL-2', 0, '', 4);
    $runningRepo->entries = [$o1, $o2];
    $now = new DateTimeImmutable('2026-10-20 15:00:00');
    $timer->start($o1, new DateTimeImmutable('2026-10-20 13:00'), $now);
    $timer->stop(new DateTimeImmutable('2026-10-20 14:00'), $now);
    $err = null;
    try { $timer->start($o2, new DateTimeImmutable('2026-10-20 13:30'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('start w środku zmierzonego czasu – odmowa', [$err, $o2->isTimerRunning()], ['Stoper OVL-1 był włączony do 20.10 14:00 – nowy może zacząć się najwcześniej o 14:00, żeby czas się nie nakładał.', false]);
    $err = null;
    try { $timer->start($o1, new DateTimeImmutable('2026-10-20 13:59'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('ten sam ticket też nie może nachodzić na siebie', [str_contains((string) $err, 'OVL-1 był włączony do 20.10 14:00'), $o1->getMinutes()], [true, 60]);
    $timer->start($o2, new DateTimeImmutable('2026-10-20 14:00'), $now);
    check('start dokładnie o zatrzymaniu poprzedniego – dozwolony', $o2->getTimerStartedAt()->format('H:i'), '14:00');
    $timer->stop(null, new DateTimeImmutable('2026-10-20 14:20:25'));
    $err = null;
    try { $timer->start($o1, new DateTimeImmutable('2026-10-20 14:20'), $now); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('zatrzymanie z sekundami – najwcześniej następna minuta', $err, 'Stoper OVL-2 był włączony do 20.10 14:20:25 – nowy może zacząć się najwcześniej o 14:21, żeby czas się nie nakładał.');
    $o2->markDeleted();
    $timer->start($o1, new DateTimeImmutable('2026-10-20 14:10'), $now);
    check('czas usuniętego wpisu nie blokuje', $o1->getTimerStartedAt()->format('H:i'), '14:10');
    $timer->stop(null, $now);
    $timer->start($o1, null, new DateTimeImmutable('2026-10-20 15:00:00'));
    check('start „teraz” bez sprawdzania (teraz jest zawsze po zatrzymaniu)', $o1->isTimerRunning(), true);
    check('najwcześniejszy stop: pełna minuta po starcie', App\Service\TimerService::earliestStop(new DateTimeImmutable('2026-10-20 11:00:40'))->format('H:i'), '11:01');

    // podejrzany stoper
    check('stale: inny dzień', str_contains((string) App\Service\TimerService::staleReason(new DateTimeImmutable('2026-10-19 15:00'), new DateTimeImmutable('2026-10-20 08:10')), 'innego dnia'), true);
    check('stale: >6h', str_contains((string) App\Service\TimerService::staleReason(new DateTimeImmutable('2026-10-20 08:00'), new DateTimeImmutable('2026-10-20 14:30')), 'ponad 6h'), true);
    check('stale: normalnie', App\Service\TimerService::staleReason(new DateTimeImmutable('2026-10-20 08:00'), new DateTimeImmutable('2026-10-20 11:00')), null);
    check('podpowiedź: koniec dnia startu', App\Service\TimerService::suggestedStop(new DateTimeImmutable('2026-10-19 15:00'), new DateTimeImmutable('2026-10-20 08:10'), '16:00')->format('Y-m-d H:i'), '2026-10-19 16:00');
    check('podpowiedź: po końcu dnia = minuta po starcie', App\Service\TimerService::suggestedStop(new DateTimeImmutable('2026-10-19 17:20'), new DateTimeImmutable('2026-10-20 08:10'), '16:00')->format('Y-m-d H:i'), '2026-10-19 17:21');
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

    // --- podsumowanie miesiąca (listopad 2026: 1.11 niedziela, 11.11 środa, urlop 9–13.11, dziś 18.11) ---
    $absenceRepo->items = [new App\Entity\Absence(new DateTimeImmutable('2026-11-09'), new DateTimeImmutable('2026-11-13'), 'urlop', 'Zakopane')];
    $mcal = new App\Service\WorkCalendar($absenceRepo);
    $months = new App\Service\MonthReport(new ReportBuilder($settings, $mcal), $mcal, $settings);
    $mdays = [];
    foreach (['2026-11-02' => [['ABC-1', 480, 'a']], '2026-11-03' => [['ABC-1', 300, '=SUM(A1)'], ['ABC-2', 60, '']],
              '2026-11-04' => [['ABC-2', 420, 'b']], '2026-11-07' => [['OPS-1', 120, 'sobota']]] as $date => $list) {
        $mdays[$date] = new WorkDay(new DateTimeImmutable($date));
        foreach ($list as $i => [$k, $min, $desc]) {
            new WorklogEntry($mdays[$date], $k, $min, $desc, $i);
        }
    }
    $mdays['2026-11-05'] = new WorkDay(new DateTimeImmutable('2026-11-05')); // zapisany dzień bez wpisów = brak raportu
    $m = $months->build(new DateTimeImmutable('2026-11-01'), $mdays, new DateTimeImmutable('2026-11-18'));
    $mt = $m['totals'];
    check('miesiąc: dni robocze i norma', [$mt['workingDays'], $mt['normMonth']], [16, 16 * 420]);
    check('miesiąc: bilans, nadgodziny, krócej', [$mt['balance'], $mt['over'], $mt['under'], $mt['overDays'], $mt['underDays']], [120, 180, 60, 2, 1]);
    check('miesiąc: czas pracy i norma dni z raportem', [$mt['worked'], $mt['logged'], $mt['normReported'], $mt['reported']], [1380, 1380, 1260, 4]);
    check('miesiąc: brak raportu (bez dziś i przyszłości)', $mt['missing'], 4);
    check('miesiąc: dni wolne w tygodniu', $m['offCounts'], ['Urlop' => 4, 'Święto' => 1]);
    $mstatus = array_column(array_map(static fn (array $r) => [$r['date']->format('j'), $r['status']], $m['rows']), 1, 0);
    check('miesiąc: wiersze', [count($m['rows']), $mstatus['7'], $mstatus['11'], $mstatus['18'], $mstatus['19'], isset($mstatus['8'])],
        [22, 'reported', 'off', 'today', 'future', false]);
    check('miesiąc: tickety od największego', array_map(static fn (array $t) => [$t['minutes'], $t['days']], $m['tickets']),
        ['ABC-1' => [780, 2], 'ABC-2' => [480, 2], 'OPS-1' => [120, 1]]);
    $dcsv = $months->daysCsv($m);
    $dlines = explode("\r\n", rtrim($dcsv));
    check('CSV dni: BOM i nagłówek', $dlines[0], "\u{FEFF}Data;Dzień;Od;Do;Przerwa;\"Czas pracy\";\"W ticketach\";Norma;Bilans;Uwagi");
    check('CSV dni: wiersz', $dlines[1], '2026-11-02;poniedziałek;;;0:00;8:00;8:00;7:00;+1:00;');
    check('CSV dni: urlop i brak raportu', [$dlines[4], $dlines[7]], ['2026-11-05;czwartek;;;;;;;;"brak raportu"', '2026-11-09;poniedziałek;;;;;;;;"Urlop – Zakopane"']);
    check('CSV dni: razem', end($dlines), 'Razem;;;;;23:00;23:00;21:00;+2:00;"dni bez raportu: 4"');
    $ecsv = explode("\r\n", rtrim($months->entriesCsv($m)));
    check('CSV wpisy', [count($ecsv), $ecsv[2]], [6, "2026-11-03;ABC-1;;5:00;300;'=SUM(A1);nie"]);
    check('miesiąc z „2026-13”', [App\Service\MonthReport::parseMonth('2026-13'), App\Service\MonthReport::parseMonth('2026-02')?->format('Y-m-d')], [null, '2026-02-01']);
    check('nazwa miesiąca', App\Domain\Template::monthName(new DateTimeImmutable('2026-10-14')), 'październik 2026');
    $absenceRepo->items = [];

    // --- przypomnienie o niewysłanym mailu o planowanej zmianie ---
    $rtoday = new DateTimeImmutable('2026-10-14 15:45');
    check('dzień względny', array_map(static fn (string $d) => App\Domain\Template::relativeDay(new DateTimeImmutable($d), $rtoday), ['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-13']),
        ['dziś', 'jutro', 'piątek 16.10', 'wtorek 13.10']);
    check('bez maili do wysłania', App\Domain\ReminderSchedule::plannedTodo([], $rtoday), null);
    $late = new PlannedChange(new DateTimeImmutable('2026-10-15'), PlannedChange::TYPE_LATE, '10:00', null, '');
    check('jeden mail', App\Domain\ReminderSchedule::plannedTodo([$late], $rtoday), 'wyślij mail o planowanej zmianie (jutro: późniejsze przyjście)');
    $early = new PlannedChange(new DateTimeImmutable('2026-10-16'), PlannedChange::TYPE_EARLY, null, '14:00', '');
    check('dwa maile', App\Domain\ReminderSchedule::plannedTodo([$late, $early], $rtoday),
        'wyślij maile o planowanych zmianach (jutro: późniejsze przyjście; piątek 16.10: wcześniejsze wyjście)');

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
