<?php

namespace App\Mcp;

use App\Entity\WorklogEntry;
use App\Service\WorklogSync;

final readonly class EntryView
{
    public function __construct(
        private(set) string $ticket,
        private(set) string $title,
        private(set) int $minutes,
        private(set) string $description,
        private(set) string $jira,
        private(set) ?string $jiraError,
    ) {
    }

    public static function of(WorklogEntry $entry, string $jiraStatus): self
    {
        return new self(
            ticket: $entry->getIssueKey(),
            title: $entry->getIssueTitle(),
            minutes: $entry->getMinutes(),
            description: $entry->getDescription(),
            jira: $jiraStatus,
            jiraError: WorklogSync::STATUS_ERROR === $jiraStatus ? $entry->getJiraError() : null,
        );
    }
}
