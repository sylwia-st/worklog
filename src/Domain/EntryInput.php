<?php

namespace App\Domain;

final readonly class EntryInput
{
    public function __construct(
        private(set) string $issueKey,
        private(set) int $minutes,
        private(set) string $description = '',
        private(set) string $title = '',
    ) {
    }

    public static function fromArray(array $entry): self
    {
        $ticket = trim($entry['ticket']);

        return new self(
            issueKey: Template::extractIssueKey($ticket) ?? throw new \DomainException(sprintf('Niepoprawny numer ticketu „%s” (np. ABC-123).', $ticket)),
            minutes: $entry['minutes'],
            description: trim($entry['description'] ?? ''),
            title: trim($entry['title'] ?? Template::issueTitleAfterKey($ticket)),
        );
    }
}
