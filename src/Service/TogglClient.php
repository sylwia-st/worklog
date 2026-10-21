<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Komunikacja wyłącznie z API Toggl Track (api.track.toggl.com), tylko do odczytu.
 * Logowanie: token API użytkownika (Basic auth: login = token, hasło = „api_token”).
 */
final class TogglClient
{
    private const BASE = 'https://api.track.toggl.com/api/v9';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Settings $settings,
    ) {
    }

    /** @return array{name: string, email: string} */
    public function me(): array
    {
        $me = $this->request('/me');

        return [
            'name' => (string) ($me['fullname'] ?? '?'),
            'email' => (string) ($me['email'] ?? ''),
        ];
    }

    /**
     * Wpisy użytkownika ze wszystkich workspace'ów; $to to górna granica (wyłączna).
     * Daty podajemy zawsze – bez nich Toggl zwraca tylko ostatnie 9 dni.
     *
     * @return list<array<string, mixed>>
     */
    public function timeEntries(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $data = $this->request('/me/time_entries', ['query' => [
            'start_date' => $from->format(\DATE_RFC3339),
            'end_date' => $to->format(\DATE_RFC3339),
        ]]);

        if (!array_is_list($data)) {
            throw new TogglException('Nieoczekiwana odpowiedź Toggla (spodziewano się listy wpisów).');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /** @return array<int, string> id projektu → nazwa */
    public function projects(): array
    {
        $out = [];
        foreach ($this->request('/me/projects') as $project) {
            if (is_array($project) && isset($project['id'])) {
                $out[(int) $project['id']] = (string) ($project['name'] ?? '');
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function request(string $path, array $options = []): array
    {
        $token = trim($this->settings->get('toggl_token'));
        if ('' === $token) {
            throw new TogglException('Wpisz token Toggla w Ustawieniach.');
        }

        $options['headers'] = ['Accept' => 'application/json'];
        $options['auth_basic'] = [$token, 'api_token'];

        try {
            $response = $this->http->request('GET', self::BASE.$path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new TogglException('Brak połączenia z Togglem: '.$e->getMessage());
        }

        if ($status >= 400) {
            throw new TogglException($this->describeError($status, $content), $status);
        }
        // pusta odpowiedź albo „null” (np. brak projektów) to po prostu brak danych
        if ('' === trim($content) || 'null' === trim($content)) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new TogglException('Nieoczekiwana odpowiedź Toggla (to nie JSON).', $status);
        }

        return $data;
    }

    private function describeError(int $status, string $content): string
    {
        // Toggl zwraca zwykle zwykły tekst błędu, czasem JSON z polem „message”
        $data = json_decode($content, true);
        $detail = is_array($data) ? (string) ($data['message'] ?? $data['error'] ?? '') : trim($content);
        $detail = '' !== $detail ? ' – '.mb_substr($detail, 0, 200) : '';

        return match (true) {
            401 === $status || 403 === $status => sprintf('Toggl odrzucił token (%d) – sprawdź token API w Ustawieniach.', $status),
            429 === $status => 'Toggl ogranicza liczbę zapytań (429) – spróbuj za chwilę.',
            default => sprintf('Błąd Toggla (%d)%s', $status, $detail),
        };
    }
}
