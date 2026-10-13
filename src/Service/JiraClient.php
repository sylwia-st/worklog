<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Komunikacja wyłącznie z adresem Jiry podanym w ustawieniach.
 *
 * Jira Cloud (*.atlassian.net): e-mail + API token (Basic auth).
 * Jira Server / Data Center:   pole e-mail puste, Personal Access Token (Bearer).
 */
final class JiraClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Settings $settings,
    ) {
    }

    public function isCloud(): bool
    {
        return '' !== trim($this->settings->get('jira_email'));
    }

    /** @return array{name: string, account: string} */
    public function myself(): array
    {
        $me = $this->request('GET', '/rest/api/2/myself');

        return [
            'name' => (string) ($me['displayName'] ?? $me['name'] ?? '?'),
            'account' => (string) ($me['emailAddress'] ?? $me['name'] ?? $me['accountId'] ?? ''),
        ];
    }

    /** @return array<string, string> klucz → tytuł */
    public function searchIssues(string $jql, int $max = 100): array
    {
        $query = ['jql' => $jql, 'fields' => 'summary', 'maxResults' => $max];
        // Cloud wycofał /rest/api/2/search na rzecz /rest/api/3/search/jql; Server/DC dalej używa v2.
        $path = $this->isCloud() ? '/rest/api/3/search/jql' : '/rest/api/2/search';
        $data = $this->request('GET', $path, ['query' => $query]);

        $out = [];
        foreach ($data['issues'] ?? [] as $issue) {
            if (isset($issue['key'])) {
                $out[(string) $issue['key']] = (string) ($issue['fields']['summary'] ?? '');
            }
        }

        return $out;
    }

    public function addWorklog(string $issueKey, \DateTimeImmutable $started, int $seconds, string $comment): string
    {
        $data = $this->request('POST', '/rest/api/2/issue/'.rawurlencode($issueKey).'/worklog', [
            'json' => $this->payload($started, $seconds, $comment),
        ]);
        if (!isset($data['id'])) {
            throw new JiraException('Jira nie zwróciła identyfikatora worklogu.');
        }

        return (string) $data['id'];
    }

    public function updateWorklog(string $issueKey, string $worklogId, \DateTimeImmutable $started, int $seconds, string $comment): void
    {
        $this->request('PUT', '/rest/api/2/issue/'.rawurlencode($issueKey).'/worklog/'.rawurlencode($worklogId), [
            'json' => $this->payload($started, $seconds, $comment),
        ]);
    }

    public function deleteWorklog(string $issueKey, string $worklogId): void
    {
        try {
            $this->request('DELETE', '/rest/api/2/issue/'.rawurlencode($issueKey).'/worklog/'.rawurlencode($worklogId));
        } catch (JiraException $e) {
            if (404 !== $e->status) { // już nie istnieje – traktujemy jako sukces
                throw $e;
            }
        }
    }

    /** @return array<string, mixed> */
    private function payload(\DateTimeImmutable $started, int $seconds, string $comment): array
    {
        $payload = [
            // format wymagany przez Jirę: 2026-10-13T08:00:00.000+0200
            'started' => $started->format('Y-m-d\TH:i:s.vO'),
            'timeSpentSeconds' => $seconds,
        ];
        if ('' !== trim($comment)) {
            $payload['comment'] = $comment;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $base = rtrim(trim($this->settings->get('jira_url')), '/');
        $token = trim($this->settings->get('jira_token'));
        if ('' === $base || '' === $token) {
            throw new JiraException('Uzupełnij adres Jiry i token w Ustawieniach.');
        }
        if (!str_starts_with($base, 'https://') && !str_starts_with($base, 'http://')) {
            throw new JiraException('Adres Jiry musi zaczynać się od https://');
        }

        $options['headers'] = ['Accept' => 'application/json'];
        if ($this->isCloud()) {
            $options['auth_basic'] = [trim($this->settings->get('jira_email')), $token];
        } else {
            $options['auth_bearer'] = $token;
        }

        try {
            $response = $this->http->request($method, $base.$path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new JiraException('Brak połączenia z Jirą: '.$e->getMessage());
        }

        if ($status >= 400) {
            throw new JiraException($this->describeError($status, $content), $status);
        }
        if ('' === trim($content)) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new JiraException('Nieoczekiwana odpowiedź Jiry (to nie JSON – sprawdź adres w ustawieniach).', $status);
        }

        return $data;
    }

    private function describeError(int $status, string $content): string
    {
        $details = [];
        $data = json_decode($content, true);
        if (is_array($data)) {
            foreach ($data['errorMessages'] ?? [] as $msg) {
                $details[] = (string) $msg;
            }
            foreach ($data['errors'] ?? [] as $field => $msg) {
                $details[] = $field.': '.(is_string($msg) ? $msg : json_encode($msg));
            }
        }
        $detail = $details ? ' – '.implode('; ', $details) : '';

        return match (true) {
            401 === $status => 'Jira odrzuciła logowanie (401). Sprawdź e-mail/token; dla Jira Server zostaw pole e-mail puste.'.$detail,
            403 === $status => 'Brak uprawnień (403).'.$detail,
            404 === $status => 'Nie znaleziono (404) – zły numer ticketu, brak dostępu albo zły adres Jiry.'.$detail,
            default => sprintf('Błąd Jiry (%d)%s', $status, $detail),
        };
    }
}
