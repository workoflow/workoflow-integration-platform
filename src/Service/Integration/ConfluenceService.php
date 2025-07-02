<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ConfluenceService
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

    public function testConnection(array $credentials): bool
    {
        $response = $this->httpClient->request('GET', $credentials['url'] . '/rest/api/user/current', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ]);

        return $response->getStatusCode() === 200;
    }

    public function search(array $credentials, string $query, int $limit = 25): array
    {
        $response = $this->httpClient->request('GET', $credentials['url'] . '/rest/api/content/search', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
            'query' => [
                'cql' => $query,
                'limit' => $limit,
            ],
        ]);

        return $response->toArray();
    }

    public function getPage(array $credentials, string $pageId): array
    {
        $response = $this->httpClient->request('GET', $credentials['url'] . '/rest/api/content/' . $pageId, [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
            'query' => [
                'expand' => 'body.storage,version',
            ],
        ]);

        return $response->toArray();
    }

    public function getComments(array $credentials, string $pageId): array
    {
        $response = $this->httpClient->request('GET', $credentials['url'] . '/rest/api/content/' . $pageId . '/child/comment', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
            'query' => [
                'expand' => 'body.storage',
            ],
        ]);

        return $response->toArray();
    }
}