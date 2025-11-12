<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use InvalidArgumentException;

class TrelloService
{
    private const BASE_URL = 'https://api.trello.com/1';

    public function __construct(
        private HttpClientInterface $httpClient
    ) {
    }

    /**
     * Build authentication query parameters for Trello API
     */
    private function buildAuthQuery(array $credentials): array
    {
        return [
            'key' => $credentials['api_key'],
            'token' => $credentials['api_token'],
        ];
    }

    /**
     * Test Trello connection by fetching current user info
     */
    public function testConnection(array $credentials): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/members/me', [
                'query' => $this->buildAuthQuery($credentials),
                'timeout' => 10,
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Search across all Trello boards, cards, and lists
     */
    public function search(array $credentials, string $query, int $maxResults = 50): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . '/search', [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'query' => $query,
                'modelTypes' => 'cards,boards,lists',
                'cards_limit' => $maxResults,
                'card_fields' => 'all',
                'boards_limit' => 25,
                'board_fields' => 'all',
            ]),
        ]);

        return $response->toArray();
    }

    /**
     * Get all boards accessible to the authenticated user
     */
    public function getBoards(array $credentials): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . '/members/me/boards', [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'fields' => 'all',
            ]),
        ]);

        return $response->toArray();
    }

    /**
     * Get specific board details
     */
    public function getBoard(array $credentials, string $boardId): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . '/boards/' . $boardId, [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'fields' => 'all',
            ]),
        ]);

        return $response->toArray();
    }

    /**
     * Get all lists on a specific board
     */
    public function getBoardLists(array $credentials, string $boardId): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . '/boards/' . $boardId . '/lists', [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'fields' => 'all',
            ]),
        ]);

        return $response->toArray();
    }

    /**
     * Get all cards on a specific board
     */
    public function getBoardCards(array $credentials, string $boardId): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . '/boards/' . $boardId . '/cards', [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'fields' => 'all',
            ]),
        ]);

        return $response->toArray();
    }

    /**
     * Get all cards in a specific list
     */
    public function getListCards(array $credentials, string $listId): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . '/lists/' . $listId . '/cards', [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'fields' => 'all',
            ]),
        ]);

        return $response->toArray();
    }

    /**
     * Get detailed information about a specific card
     */
    public function getCard(array $credentials, string $cardId): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . '/cards/' . $cardId, [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'fields' => 'all',
                'members' => 'true',
                'member_fields' => 'all',
                'labels' => 'all',
                'attachments' => 'true',
                'checklists' => 'all',
            ]),
        ]);

        return $response->toArray();
    }

    /**
     * Get comments (actions) on a specific card
     */
    public function getCardComments(array $credentials, string $cardId): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . '/cards/' . $cardId . '/actions', [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'filter' => 'commentCard',
            ]),
        ]);

        return $response->toArray();
    }

    /**
     * Get checklists on a specific card
     */
    public function getCardChecklists(array $credentials, string $cardId): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . '/cards/' . $cardId . '/checklists', [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'checkItems' => 'all',
                'checkItem_fields' => 'all',
            ]),
        ]);

        return $response->toArray();
    }

    /**
     * Create a new card in a list
     */
    public function createCard(array $credentials, array $parameters): array
    {
        $idList = $parameters['idList'] ?? throw new InvalidArgumentException('idList is required');
        $name = $parameters['name'] ?? throw new InvalidArgumentException('name is required');

        $payload = [
            'idList' => $idList,
            'name' => $name,
        ];

        // Optional parameters
        if (isset($parameters['desc'])) {
            $payload['desc'] = $parameters['desc'];
        }
        if (isset($parameters['pos'])) {
            $payload['pos'] = $parameters['pos'];
        }
        if (isset($parameters['due'])) {
            $payload['due'] = $parameters['due'];
        }
        if (isset($parameters['idMembers'])) {
            $payload['idMembers'] = $parameters['idMembers'];
        }
        if (isset($parameters['idLabels'])) {
            $payload['idLabels'] = $parameters['idLabels'];
        }

        $response = $this->httpClient->request('POST', self::BASE_URL . '/cards', [
            'query' => array_merge($this->buildAuthQuery($credentials), $payload),
        ]);

        return $response->toArray();
    }

    /**
     * Update an existing card
     */
    public function updateCard(array $credentials, string $cardId, array $parameters): array
    {
        $payload = [];

        // Allow updating various card fields
        $allowedFields = ['name', 'desc', 'closed', 'idMembers', 'idList', 'pos', 'due', 'dueComplete', 'idLabels'];
        foreach ($allowedFields as $field) {
            if (isset($parameters[$field])) {
                $payload[$field] = $parameters[$field];
            }
        }

        if (empty($payload)) {
            throw new InvalidArgumentException('At least one field to update is required');
        }

        $response = $this->httpClient->request('PUT', self::BASE_URL . '/cards/' . $cardId, [
            'query' => array_merge($this->buildAuthQuery($credentials), $payload),
        ]);

        return $response->toArray();
    }

    /**
     * Add a comment to a card
     */
    public function addComment(array $credentials, string $cardId, string $text): array
    {
        $response = $this->httpClient->request('POST', self::BASE_URL . '/cards/' . $cardId . '/actions/comments', [
            'query' => array_merge($this->buildAuthQuery($credentials), [
                'text' => $text,
            ]),
        ]);

        return $response->toArray();
    }
}
