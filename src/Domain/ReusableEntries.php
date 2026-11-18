<?php

namespace App\Domain;

use App\Entity\WorklogEntry;

final class ReusableEntries
{
    public function __construct(private array $entries)
    {
    }

    public function takeFor(EntryInput $input): ?WorklogEntry
    {
        $key = array_find_key($this->entries, static fn (WorklogEntry $entry): bool => $entry->getIssueKey() === $input->issueKey && $entry->getDescription() === $input->description)
            ?? array_find_key($this->entries, static fn (WorklogEntry $entry): bool => $entry->getIssueKey() === $input->issueKey);

        return null === $key ? null : $this->take($key);
    }

    public function remaining(): array
    {
        return array_values($this->entries);
    }

    private function take(int $key): WorklogEntry
    {
        $entry = $this->entries[$key];
        unset($this->entries[$key]);

        return $entry;
    }
}
