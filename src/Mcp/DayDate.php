<?php

namespace App\Mcp;

use Mcp\Server\Capability\Attribute\Schema;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class DayDate extends Schema
{
    public function __construct()
    {
        parent::__construct(description: 'Dzień w formacie RRRR-MM-DD, np. 2026-11-17.', format: 'date');
    }
}
