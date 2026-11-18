<?php

namespace App\Mcp;

final readonly class DayView
{
    public function __construct(
        private(set) string $date,
        private(set) string $weekday,
        private(set) ?string $dayOff,
        private(set) ?string $start,
        private(set) ?string $end,
        private(set) int $normMinutes,
        private(set) int $totalMinutes,
        private(set) array $entries,
        private(set) int $pendingJiraDeletions,
        private(set) bool $jiraConfigured,
    ) {
    }
}
