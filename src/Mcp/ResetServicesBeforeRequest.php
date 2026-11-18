<?php

namespace App\Mcp;

use Mcp\Server\Event\RequestEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

#[AsEventListener]
final readonly class ResetServicesBeforeRequest
{
    public function __construct(
        #[Autowire(service: 'services_resetter')]
        private ResetInterface $resetter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $this->resetter->reset();
    }
}
