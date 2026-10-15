<?php

namespace App\Service;

use App\Domain\DaySummary;
use App\Domain\MailDraft;
use App\Domain\Template;
use App\Domain\TimeFormat;
use App\Entity\PlannedChange;
use App\Entity\WorkDay;

/**
 * Buduje teksty na Slacka i maile z szablonów zdefiniowanych w Ustawieniach.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly Settings $settings,
        private readonly WorkCalendar $calendar,
    ) {
    }

    public function summary(WorkDay $day): DaySummary
    {
        return DaySummary::calculate(
            array_map(static fn ($e) => $e->getMinutes(), $day->getActiveEntries()),
            $day->getStartTime(),
            $day->getEndTime(),
            $day->getBreakMinutes(),
            // weekend, święto, urlop: norma 0 – praca tego dnia to w całości nadgodziny
            null !== $this->calendar->dayOff($day->getDate()) ? 0 : $this->settings->int('norm_minutes'),
            $this->settings->int('tolerance_minutes'),
            $day->hasWorkedThroughFixedBreak() ? null : ($this->settings->get('lunch_from') ?: null),
            $day->hasWorkedThroughFixedBreak() ? null : ($this->settings->get('lunch_to') ?: null),
            $day->getBreakRanges(),
        );
    }

    /** Wpisy zgrupowane per ticket (ten sam ticket wpisany dwa razy = jedna linia). */
    public function slackText(WorkDay $day): string
    {
        $vars = $this->dayVars($day, $this->summary($day));
        $lines = [];
        foreach ($this->groupedEntries($day) as $key => $g) {
            $line = Template::render($this->settings->get('slack_line'), $vars + [
                'ticket' => $key,
                'czas' => TimeFormat::format($g['minutes']),
                'opis' => implode('; ', $g['descriptions']),
                'tytul' => $g['title'],
            ]);
            $lines[] = preg_replace('/ {2,}/', ' ', $line); // pusty {tytul} zostawia podwójną spację
        }

        $parts = array_filter([
            Template::render($this->settings->get('slack_header'), $vars),
            implode("\n", $lines),
            Template::render($this->settings->get('slack_footer'), $vars),
        ], static fn (string $p) => '' !== $p);

        return implode("\n", $parts);
    }

    /** Mail o nadgodzinach albo krótszym dniu – null, jeśli bilans mieści się w tolerancji. */
    public function presenceMail(WorkDay $day): ?MailDraft
    {
        $summary = $this->summary($day);
        $prefix = match ($summary->kind()) {
            DaySummary::KIND_OVER => 'mail_over_',
            DaySummary::KIND_UNDER => 'mail_under_',
            default => null,
        };
        if (null === $prefix) {
            return null;
        }

        return $this->draft($prefix, $this->dayVars($day, $summary));
    }

    public function plannedMail(PlannedChange $change): MailDraft
    {
        $date = $change->getDate();
        $vars = [
            'data' => $date->format('d.m.Y'),
            'data_iso' => $date->format('Y-m-d'),
            'dzien' => Template::dayName($date),
            'typ' => $change->getTypeLabel(),
            'od' => $change->getFromTime() ?? $this->settings->get('default_start'),
            'do' => $change->getToTime() ?? $this->settings->get('default_end'),
            'powod' => $change->getReason(),
            'powod_linia' => '' !== trim($change->getReason()) ? 'Powód: '.trim($change->getReason()) : '',
            'podpis' => $this->settings->get('signature'),
        ];
        $vars['opis'] = Template::render($this->settings->get('plan_phrase_'.$change->getType()), $vars);

        return $this->draft('mail_plan_', $vars);
    }

    /** Ile godzin przed planowanym dniem wysyłany jest mail (ujemne = po czasie). */
    public function hoursOfNotice(PlannedChange $change, \DateTimeImmutable $now = new \DateTimeImmutable()): float
    {
        $start = TimeFormat::clockToMinutes($this->settings->get('default_start')) ?? 8 * 60;
        $moment = $change->getDate()->setTime(intdiv($start, 60), $start % 60);

        return ($moment->getTimestamp() - $now->getTimestamp()) / 3600;
    }

    /** @return array<string, string> */
    public function dayVars(WorkDay $day, DaySummary $summary): array
    {
        $date = $day->getDate();
        $lista = [];
        foreach ($this->groupedEntries($day) as $k => $g) {
            $lista[] = sprintf('%s – %s – %s', trim($k.' '.$g['title']), TimeFormat::format($g['minutes']), implode('; ', $g['descriptions']));
        }

        return [
            'data' => $date->format('d.m.Y'),
            'data_iso' => $date->format('Y-m-d'),
            'dzien' => Template::dayName($date),
            'od' => $day->getStartTime() ?? '—',
            'do' => $day->getEndTime() ?? '—',
            'przerwa' => TimeFormat::format($summary->breakMinutes),
            'obecnosc' => null !== $summary->presenceMinutes ? TimeFormat::format($summary->presenceMinutes) : '—',
            'zalogowane' => TimeFormat::format($summary->loggedMinutes),
            'norma' => TimeFormat::format($summary->normMinutes),
            'roznica' => TimeFormat::format(abs($summary->difference())),
            'lista' => implode("\n", $lista),
            'podpis' => $this->settings->get('signature'),
        ];
    }

    /** @return array<string, array{minutes: int, descriptions: string[], title: string}> tytuł: wpisany przy wpisie, inaczej z Jiry */
    private function groupedEntries(WorkDay $day): array
    {
        $out = [];
        foreach ($day->getActiveEntries() as $e) {
            if (0 === $e->getMinutes()) {
                continue; // wpis bez czasu nie trafia na Slacka ani do maila
            }
            $k = $e->getIssueKey();
            $out[$k] ??= ['minutes' => 0, 'descriptions' => [], 'title' => ''];
            $out[$k]['minutes'] += $e->getMinutes();
            if ('' === $out[$k]['title']) {
                $out[$k]['title'] = $e->getIssueTitle();
            }
            if ('' !== trim($e->getDescription()) && !in_array(trim($e->getDescription()), $out[$k]['descriptions'], true)) {
                $out[$k]['descriptions'][] = trim($e->getDescription());
            }
        }

        $cache = $this->settings->issueCache();
        foreach ($out as $k => $g) {
            if ('' === $g['title']) {
                $out[$k]['title'] = $cache[$k] ?? '';
            }
        }

        return $out;
    }

    /** @param array<string, string> $vars */
    private function draft(string $prefix, array $vars): MailDraft
    {
        $toRaw = $this->settings->get($prefix.'to');
        $ccRaw = $this->settings->get($prefix.'cc');

        return new MailDraft(
            Template::parseAddresses($toRaw),
            Template::parseAddresses($ccRaw),
            Template::render($this->settings->get($prefix.'subject'), $vars),
            Template::render($this->settings->get($prefix.'body'), $vars),
            array_merge(Template::invalidAddresses($toRaw), Template::invalidAddresses($ccRaw)),
        );
    }
}
