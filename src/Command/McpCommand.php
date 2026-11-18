<?php

namespace App\Command;

use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand(name: 'app:mcp', description: 'Serwer MCP (stdio): Claude czyta i zapisuje wpisy dnia')]
final readonly class McpCommand
{
    public function __construct(
        #[AutowireLocator('app.mcp_tool')]
        private ContainerInterface $tools,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire(env: 'default::WORKLOG_VERSION')]
        private ?string $version,
    ) {
    }

    public function __invoke(): int
    {
        return Server::builder()
            ->setServerInfo('worklog', $this->version ?: 'dev', title: 'Raport czasu pracy')
            ->setInstructions('Raport czasu pracy: każdy dzień to lista wpisów (ticket Jira, czas w minutach, opis).')
            ->setContainer($this->tools)
            ->setEventDispatcher($this->events)
            ->setLogger($this->logger)
            ->setDiscovery($this->projectDir, ['src/Mcp'])
            ->build()
            ->run(new StdioTransport(logger: $this->logger));
    }
}
