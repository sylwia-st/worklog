<?php

namespace App\Mcp;

use App\Domain\EntryInput;
use App\Repository\WorkDayRepository;
use App\Service\DayDefaults;
use App\Service\DayEntriesWriter;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\Capability\Attribute\Schema;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[McpTool(
    name: 'set_day',
    title: 'Zapisz wpisy dnia',
    description: 'Zastępuje wszystkie wpisy dnia podaną listą: dzień ma potem dokładnie te wiersze, w tej kolejności. '
        .'Istniejący wiersz z tym samym ticketem jest aktualizowany i zostaje powiązany ze swoim worklogiem w Jirze. '
        .'Wiersze spoza listy są usuwane; te już wysłane do Jiry znikną z niej dopiero przy następnej wysyłce z aplikacji. '
        .'Godzina „od” zapisanych wierszy jest czyszczona (worklogi dostaną kolejne godziny dnia). '
        .'Nic nie jest wysyłane do Jiry ani na Slacka – użytkownik sprawdza dzień w aplikacji i sam klika „Zapisz i wyślij do Jiry”. '
        .'Zwraca dzień po zapisie, tak jak get_day.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
)]
#[AutoconfigureTag('app.mcp_tool')]
final readonly class SetDayTool
{
    private const ENTRY_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'ticket' => ['type' => 'string', 'pattern' => '[A-Za-z][A-Za-z0-9_]+-[0-9]+', 'description' => 'Numer ticketu Jira, np. BHPS-521. Może być z tytułem: „BHPS-521 Aktualizacja zmian”.'],
            'minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1440, 'description' => 'Czas w minutach.'],
            'description' => ['type' => 'string', 'description' => 'Co zostało zrobione – trafia do worklogu w Jirze i do podsumowania na Slacka.'],
            'title' => ['type' => 'string', 'description' => 'Tytuł ticketu. Pominięty: zostaje dotychczasowy albo znany z wcześniejszych dni.'],
        ],
        'required' => ['ticket', 'minutes'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkDayRepository $days,
        private DayDefaults $defaults,
        private DayEntriesWriter $writer,
        private DayViewFactory $views,
    ) {
    }

    public function __invoke(
        #[DayDate] string $date,
        #[Schema(description: 'Wszystkie wpisy dnia. Pusta lista usuwa wpisy dnia.', items: self::ENTRY_SCHEMA)] array $entries,
    ): DayView {
        $dayDate = new \DateTimeImmutable($date);
        $day = $this->days->findByDate($dayDate);
        if (null === $day && [] === $entries) {
            return $this->views->forDate($dayDate);
        }

        $day ??= $this->defaults->newDay($dayDate);
        try {
            $this->writer->replace($day, array_map(EntryInput::fromArray(...), $entries));
        } catch (\DomainException $exception) {
            throw new ToolCallException($exception->getMessage(), previous: $exception);
        }

        $this->entityManager->persist($day);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $this->views->forDate($dayDate);
    }
}
