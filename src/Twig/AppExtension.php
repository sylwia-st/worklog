<?php

namespace App\Twig;

use App\Domain\Template;
use App\Domain\TimeFormat;
use App\Service\Settings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('hm', static fn (?int $m) => null === $m ? '—' : TimeFormat::format($m)),
            new TwigFilter('signed_hm', static fn (int $m) => ($m > 0 ? '+' : '').TimeFormat::format($m)),
            new TwigFilter('pl_day', static fn (\DateTimeInterface $d) => Template::dayName($d)),
            // „14 października” (rok tylko, gdy inny niż bieżący)
            new TwigFilter('pl_date', static fn (\DateTimeInterface $d) => Template::longDate($d, new \DateTimeImmutable('today'))),
        ];
    }

    public function getFunctions(): array
    {
        return [
            // tekst w formie wybranej przy instalacji: forma('Pracowałam', 'Pracowałem', 'Praca')
            new TwigFunction('forma', $this->settings->form(...)),
            // „5 dni roboczych”: plural(n, 'dzień roboczy', 'dni robocze', 'dni roboczych')
            new TwigFunction('plural', Template::plural(...)),
            // „dziś”, „jutro”, „piątek 16.10”
            new TwigFunction('rel_day', static fn (\DateTimeInterface $d) => Template::relativeDay($d, new \DateTimeImmutable('today'))),
        ];
    }
}
