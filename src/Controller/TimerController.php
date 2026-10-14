<?php

namespace App\Controller;

use App\Repository\WorklogEntryRepository;
use App\Service\TimerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TimerController extends AbstractController
{
    public function __construct(private readonly TimerService $timer)
    {
    }

    #[Route('/timer', name: 'timer_state', methods: ['GET'])]
    public function state(): JsonResponse
    {
        return $this->json($this->timer->state());
    }

    #[Route('/timer/start', name: 'timer_start', methods: ['POST'])]
    public function start(Request $request, WorklogEntryRepository $entries): JsonResponse
    {
        $body = $request->toArray();
        $entry = $entries->find((int) ($body['entryId'] ?? 0));
        if (null === $entry || $entry->isDeleted()) {
            return $this->json(['error' => 'Nie znaleziono wpisu – zapisz dzień i spróbuj ponownie.'], 404);
        }
        $at = null;
        $raw = (string) ($body['at'] ?? '');
        if ('' !== $raw) {
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw) ?: null;
            if (null === $at) {
                return $this->json(['error' => 'Niepoprawna godzina startu.'], 422);
            }
        }
        try {
            $this->timer->start($entry, $at);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json($this->timer->state());
    }

    #[Route('/timer/stop', name: 'timer_stop', methods: ['POST'])]
    public function stop(Request $request): JsonResponse
    {
        $at = null;
        $raw = (string) ($request->toArray()['at'] ?? '');
        if ('' !== $raw) {
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw) ?: null;
            if (null === $at) {
                return $this->json(['error' => 'Niepoprawna godzina zatrzymania.'], 422);
            }
        }
        try {
            $stopped = $this->timer->stop($at);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json($this->timer->state() + ['stopped' => null === $stopped ? null : [
            'entryId' => $stopped->getId(),
            'minutes' => $stopped->getMinutes(),
            'carrySeconds' => $stopped->getCarrySeconds(),
            'date' => $stopped->getDay()->getDate()->format('Y-m-d'),
        ]]);
    }
}
