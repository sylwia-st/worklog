<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Aplikacja trzyma token do Jiry, więc:
 *  - odpowiada tylko na Host 127.0.0.1/localhost (ochrona przed DNS rebinding),
 *  - odrzuca POST-y wysłane z innych stron (ochrona przed CSRF z przeglądanych witryn).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final class LocalOnlyGuard
{
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();

        if (!in_array($request->getHost(), ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
            $event->setResponse(new Response('Dostęp tylko z localhost.', Response::HTTP_FORBIDDEN));

            return;
        }

        if ($request->isMethodSafe()) {
            return;
        }

        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        $origin = $request->headers->get('Origin');
        if ((null !== $fetchSite && !in_array($fetchSite, ['same-origin', 'none'], true))
            || (null !== $origin && 'null' !== $origin && $origin !== $request->getSchemeAndHttpHost())
        ) {
            $event->setResponse(new Response('Odrzucono żądanie wysłane z innej strony.', Response::HTTP_FORBIDDEN));
        }
    }
}
