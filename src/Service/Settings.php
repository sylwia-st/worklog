<?php

namespace App\Service;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ustawienia trzymane lokalnie w SQLite (wolumen Dockera worklog-data, plik worklog.db, uprawnienia 600).
 */
final class Settings
{
    /** Forma w tekstach: żeńska, męska, neutralna – wybierana przy instalacji, zmienialna w Ustawieniach. */
    public const FORMS = ['f' => 'żeńska', 'm' => 'męska', 'n' => 'neutralna'];

    /** Domyślne teksty pisane w pierwszej osobie – wariant zależy od formy (dopóki użytkownik ich nie zmieni). */
    public const GENDERED = [
        'mail_over_body' => [
            'f' => "Dzień dobry,\n\nw dniu {data} ({dzien}) pracowałam dłużej o {roznica} – w godzinach {od}–{do}.\n\nPozdrawiam\n{podpis}",
            'm' => "Dzień dobry,\n\nw dniu {data} ({dzien}) pracowałem dłużej o {roznica} – w godzinach {od}–{do}.\n\nPozdrawiam\n{podpis}",
            'n' => "Dzień dobry,\n\nw dniu {data} ({dzien}) mój czas pracy był dłuższy o {roznica} – w godzinach {od}–{do}.\n\nPozdrawiam\n{podpis}",
        ],
        'mail_under_body' => [
            'f' => "Dzień dobry,\n\nw dniu {data} ({dzien}) pracowałam krócej o {roznica} – w godzinach {od}–{do}.\n\nPozdrawiam\n{podpis}",
            'm' => "Dzień dobry,\n\nw dniu {data} ({dzien}) pracowałem krócej o {roznica} – w godzinach {od}–{do}.\n\nPozdrawiam\n{podpis}",
            'n' => "Dzień dobry,\n\nw dniu {data} ({dzien}) mój czas pracy był krótszy o {roznica} – w godzinach {od}–{do}.\n\nPozdrawiam\n{podpis}",
        ],
    ];

    public const DEFAULTS = [
        'forma' => 'n',

        // Jira
        'jira_url' => '',
        'jira_email' => '',
        'jira_token' => '',
        'jira_jql' => 'assignee = currentUser() AND statusCategory != Done ORDER BY updated DESC',
        'jira_issue_cache' => '[]',

        // Dzień pracy
        'default_start' => '08:00',
        'default_end' => '16:00',
        'lunch_from' => '12:00',
        'lunch_to' => '13:00',
        'default_break' => '0',
        'norm_minutes' => '420',
        'tolerance_minutes' => '0',
        'signature' => '',

        // Przypomnienie (pon–pt); puste = wyłączone
        'remind_time' => '15:45',
        'remind_last_sent' => '',

        // Slack
        'slack_header' => 'Raport z {data} ({dzien}):',
        'slack_line' => '• {ticket} – {czas} – {opis}',
        'slack_footer' => 'Razem: {zalogowane}',

        // Mail: nadgodziny
        'mail_over_to' => '',
        'mail_over_cc' => '',
        'mail_over_subject' => 'Nadgodziny – {data}',
        'mail_over_body' => self::GENDERED['mail_over_body']['n'],

        // Mail: krótszy dzień (rozliczenie po fakcie)
        'mail_under_to' => '',
        'mail_under_cc' => '',
        'mail_under_subject' => 'Krótszy dzień pracy – {data}',
        'mail_under_body' => self::GENDERED['mail_under_body']['n'],

        // Mail: zaplanowana zmiana (z wyprzedzeniem)
        'mail_plan_to' => '',
        'mail_plan_cc' => '',
        // domyślnie sama data – resztę dopisuje się przed wysłaniem albo ustawia własny szablon
        'mail_plan_subject' => '{data}',
        'mail_plan_body' => '{data}',
        'plan_phrase_late' => 'przyjdę później, ok. godz. {od}',
        'plan_phrase_early' => 'wyjdę wcześniej, ok. godz. {do}',
        'plan_phrase_short' => 'będę w pracy krócej, w godzinach {od}–{do}',
        'plan_notice_hours' => '24',
    ];

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(string $name): string
    {
        $all = $this->all();
        if (!array_key_exists($name, $all)) {
            throw new \InvalidArgumentException('Nieznane ustawienie: '.$name);
        }

        return $all[$name];
    }

    public function int(string $name): int
    {
        return (int) $this->get($name);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if (null === $this->cache) {
            $stored = [];
            foreach ($this->em->getRepository(Setting::class)->findAll() as $s) {
                if (array_key_exists($s->getName(), self::DEFAULTS)) {
                    $stored[$s->getName()] = $s->getValue();
                }
            }
            $form = $stored['forma'] ?? self::DEFAULTS['forma'];
            $this->cache = $stored + self::defaultsFor(isset(self::FORMS[$form]) ? $form : self::DEFAULTS['forma']);
        }

        return $this->cache;
    }

    /** @return array<string, string> domyślne ustawienia z tekstami w danej formie */
    public static function defaultsFor(string $form): array
    {
        $out = self::DEFAULTS;
        $out['forma'] = $form;
        foreach (self::GENDERED as $key => $variants) {
            $out[$key] = $variants[$form];
        }

        return $out;
    }

    /** Tekst w wybranej formie, np. form('Pracowałam', 'Pracowałem', 'Praca'). */
    public function form(string $f, string $m, string $n): string
    {
        return ['f' => $f, 'm' => $m][$this->get('forma')] ?? $n;
    }

    /** @param array<string, string> $values */
    public function save(array $values): void
    {
        // zmiana formy: teksty, których użytkownik nie zmieniał (= domyślne w starej formie), przechodzą na nową
        $old = $this->get('forma');
        $new = $values['forma'] ?? $old;
        if ($new !== $old && isset(self::FORMS[$new])) {
            foreach (self::GENDERED as $key => $variants) {
                $current = str_replace("\r\n", "\n", $values[$key] ?? $this->get($key));
                if ($current === ($variants[$old] ?? null)) {
                    $values[$key] = $variants[$new];
                }
            }
        }

        $repo = $this->em->getRepository(Setting::class);
        foreach ($values as $name => $value) {
            if (!array_key_exists($name, self::DEFAULTS)) {
                continue;
            }
            $setting = $repo->find($name);
            if (null === $setting) {
                $this->em->persist(new Setting($name, $value));
            } else {
                $setting->setValue($value);
            }
        }
        $this->em->flush();
        $this->cache = null;
    }

    /** @return array<string, string> klucz → tytuł */
    public function issueCache(): array
    {
        $data = json_decode($this->get('jira_issue_cache'), true);

        return is_array($data) ? $data : [];
    }
}
