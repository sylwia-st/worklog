<?php

namespace App\Mcp;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Capability\Attribute\McpTool;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[McpTool(
    name: 'get_day',
    title: 'Odczytaj dzień',
    description: 'Odczytuje dzień z raportu czasu pracy: wpisy (ticket, tytuł, minuty, opis, stan w Jirze), sumę i normę w minutach oraz czy to dzień wolny. '
        .'Stan w Jirze: new – jeszcze niewysłany, synced – w Jirze, changed – zmieniony po wysłaniu, error – błąd Jiry (treść w jiraError), empty – 0 minut. '
        .'Wywołaj przed set_day, żeby zobaczyć, co już jest (np. wpisy dodane ręcznie przez użytkownika).',
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
)]
#[AutoconfigureTag('app.mcp_tool')]
final readonly class GetDayTool
{
    public function __construct(private DayViewFactory $views)
    {
    }

    public function __invoke(#[DayDate] string $date): DayView
    {
        return $this->views->forDate(new \DateTimeImmutable($date));
    }
}
