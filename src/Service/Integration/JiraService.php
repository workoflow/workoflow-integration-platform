<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class JiraService
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

    public function testConnection(array $credentials): bool
    {
        $response = $this->httpClient->request('GET', $credentials['url'] . '/rest/api/3/myself', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ]);

        return $response->getStatusCode() === 200;
    }

    public function search(array $credentials, string $jql, int $maxResults = 50): array
    {
        $response = $this->httpClient->request('GET', $credentials['url'] . '/rest/api/3/search', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
            'query' => [
                'jql' => $jql,
                'maxResults' => $maxResults,
            ],
        ]);

        return $response->toArray();
    }

    public function getIssue(array $credentials, string $issueKey): array
    {
        $response = $this->httpClient->request('GET', $credentials['url'] . '/rest/api/3/issue/' . $issueKey, [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ]);

        return $response->toArray();
    }

    public function getSprintsFromBoard(array $credentials, int $boardId): array
    {
        $response = $this->httpClient->request('GET', $credentials['url'] . '/rest/agile/1.0/board/' . $boardId . '/sprint', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ]);

        return $response->toArray();
    }

    public function getSprintIssues(array $credentials, int $sprintId): array
    {
        $response = $this->httpClient->request('GET', $credentials['url'] . '/rest/agile/1.0/sprint/' . $sprintId . '/issue', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ]);

        return $response->toArray();
    }
}