<?php

namespace App\Service\Integration;

use App\Entity\IntegrationConfig;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WrikeService
{
    private const TOKEN_REFRESH_BUFFER = 300; // 5 minutes buffer before expiry

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private EncryptionService $encryptionService
    ) {
    }

    /**
     * Get API base URL from credentials (datacenter-specific)
     */
    private function getApiBaseUrl(array $credentials): string
    {
        $host = $credentials['host'] ?? 'www.wrike.com';
        return "https://{$host}/api/v4";
    }

    /**
     * Test Wrike connection with detailed error reporting
     *
     * @param array $credentials Wrike credentials (OAuth tokens)
     * @return array Detailed test result with success, message, details, and suggestions
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            // Ensure we have a valid token
            $credentials = $this->ensureValidToken($credentials);
            $baseUrl = $this->getApiBaseUrl($credentials);

            // Test authentication endpoint - get current user info
            try {
                $response = $this->httpClient->request(
                    'GET',
                    $baseUrl . '/contacts?me=true',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $credentials['access_token'],
                            'Content-Type' => 'application/json',
                        ],
                        'timeout' => 10,
                    ]
                );

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/contacts?me=true',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    $data = $response->toArray();
                    $userData = $data['data'][0] ?? [];
                    $firstName = $userData['firstName'] ?? 'Unknown';
                    $lastName = $userData['lastName'] ?? '';
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'details' => 'Successfully connected to Wrike. User: ' . trim("{$firstName} {$lastName}"),
                        'suggestion' => '',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: Unexpected response from Wrike API",
                        'suggestion' => 'Check the error details and verify your Wrike access.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            /** @phpstan-ignore-next-line catch.neverThrown */
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/contacts?me=true',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Invalid or expired access token. Please reconnect to Wrike.',
                        'suggestion' => 'Click "Reconnect" to refresh your Wrike authorization.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'Your Wrike app does not have sufficient permissions.',
                        'suggestion' => 'Ensure the app has the wsReadWrite scope.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: " . $e->getMessage(),
                        'suggestion' => 'Check the error details and verify your Wrike access.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach Wrike API',
                    'details' => 'Network error: ' . $e->getMessage(),
                    'suggestion' => 'Check your network connection.',
                    'tested_endpoints' => $testedEndpoints
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Unexpected error',
                'details' => $e->getMessage(),
                'suggestion' => 'Please check your configuration and try again.',
                'tested_endpoints' => $testedEndpoints
            ];
        }
    }

    /**
     * Ensure the access token is valid, refreshing if necessary
     *
     * @param array $credentials Current credentials
     * @param IntegrationConfig|null $config Optional config for persisting updated tokens
     * @return array Updated credentials with valid token
     */
    public function ensureValidToken(array $credentials, ?IntegrationConfig $config = null): array
    {
        $expiresAt = $credentials['expires_at'] ?? 0;

        // Check if token needs refresh (with 5 minute buffer)
        if (time() >= ($expiresAt - self::TOKEN_REFRESH_BUFFER)) {
            $this->logger->info('Wrike access token expiring soon, refreshing...');
            $credentials = $this->refreshAccessToken($credentials, $config);
        }

        return $credentials;
    }

    /**
     * Refresh the access token using the refresh token
     *
     * @param array $credentials Current credentials with refresh_token
     * @param IntegrationConfig|null $config Optional config for persisting updated tokens
     * @return array Updated credentials with new access token
     */
    private function refreshAccessToken(array $credentials, ?IntegrationConfig $config = null): array
    {
        if (empty($credentials['refresh_token'])) {
            throw new \RuntimeException('No refresh token available. Please reconnect to Wrike.');
        }

        $clientId = $_ENV['WRIKE_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['WRIKE_CLIENT_SECRET'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('Wrike OAuth credentials not configured.');
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                'https://login.wrike.com/oauth2/token',
                [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'body' => [
                        'grant_type' => 'refresh_token',
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'refresh_token' => $credentials['refresh_token'],
                    ],
                ]
            );

            $data = $response->toArray();

            // Update credentials - preserve host from original credentials
            $credentials['access_token'] = $data['access_token'];
            $credentials['refresh_token'] = $data['refresh_token'] ?? $credentials['refresh_token'];
            $credentials['expires_at'] = time() + ($data['expires_in'] ?? 3600);

            // Update host if provided in response
            if (isset($data['host'])) {
                $credentials['host'] = $data['host'];
            }

            // Persist updated tokens if config provided
            if ($config !== null) {
                $config->setEncryptedCredentials(
                    $this->encryptionService->encrypt(json_encode($credentials))
                );
                $this->entityManager->flush();
                $this->logger->info('Wrike tokens refreshed and persisted.');
            }

            return $credentials;
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $this->logger->error('Failed to refresh Wrike token: ' . $e->getMessage());
            throw new \RuntimeException(
                'Failed to refresh Wrike access token. Please reconnect to Wrike.',
                0,
                $e
            );
        }
    }

    // ========================================
    // TASKS API
    // ========================================

    /**
     * Search for tasks
     *
     * @param array $credentials Wrike credentials
     * @param string|null $title Filter by title (contains)
     * @param string|null $status Filter by status (Active, Completed, Deferred, Cancelled)
     * @param string|null $folderId Filter by folder/project ID
     * @param int $limit Maximum results
     * @return array Search results
     */
    public function searchTasks(
        array $credentials,
        ?string $title = null,
        ?string $status = null,
        ?string $folderId = null,
        int $limit = 100
    ): array {
        $credentials = $this->ensureValidToken($credentials);

        $queryParams = [
            'pageSize' => min($limit, 1000),
        ];

        if ($title) {
            $queryParams['title'] = $title;
        }
        if ($status) {
            $queryParams['status'] = $status;
        }

        // If folder specified, use folder-specific endpoint
        $endpoint = $folderId
            ? "/folders/{$folderId}/tasks"
            : '/tasks';

        return $this->makeApiRequest(
            $credentials,
            'GET',
            $endpoint,
            null,
            $queryParams
        );
    }

    /**
     * Get a task by ID
     *
     * @param array $credentials Wrike credentials
     * @param string $taskId Task ID
     * @return array Task data
     */
    public function getTask(array $credentials, string $taskId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            "/tasks/{$taskId}"
        );
    }

    /**
     * Create a new task
     *
     * @param array $credentials Wrike credentials
     * @param string $folderId Folder/project ID to create task in
     * @param string $title Task title
     * @param string|null $description Task description
     * @param string|null $status Task status (Active, Completed, Deferred, Cancelled)
     * @param array $assignees Array of user IDs to assign
     * @param string|null $startDate Start date (YYYY-MM-DD)
     * @param string|null $dueDate Due date (YYYY-MM-DD)
     * @return array Created task data
     */
    public function createTask(
        array $credentials,
        string $folderId,
        string $title,
        ?string $description = null,
        ?string $status = null,
        array $assignees = [],
        ?string $startDate = null,
        ?string $dueDate = null
    ): array {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'title' => $title,
        ];

        if ($description !== null) {
            $payload['description'] = $description;
        }
        if ($status !== null) {
            $payload['status'] = $status;
        }
        if (!empty($assignees)) {
            $payload['responsibles'] = $assignees;
        }

        // Handle dates
        $dates = [];
        if ($startDate !== null) {
            $dates['start'] = $startDate;
        }
        if ($dueDate !== null) {
            $dates['due'] = $dueDate;
        }
        if (!empty($dates)) {
            $payload['dates'] = $dates;
        }

        return $this->makeApiRequest(
            $credentials,
            'POST',
            "/folders/{$folderId}/tasks",
            $payload
        );
    }

    /**
     * Update an existing task
     *
     * @param array $credentials Wrike credentials
     * @param string $taskId Task ID
     * @param array $updates Array of fields to update
     * @return array Updated task data
     */
    public function updateTask(array $credentials, string $taskId, array $updates): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [];

        if (isset($updates['title'])) {
            $payload['title'] = $updates['title'];
        }
        if (isset($updates['description'])) {
            $payload['description'] = $updates['description'];
        }
        if (isset($updates['status'])) {
            $payload['status'] = $updates['status'];
        }
        if (isset($updates['assignees'])) {
            $payload['responsibles'] = $updates['assignees'];
        }

        // Handle date updates
        $dates = [];
        if (isset($updates['startDate'])) {
            $dates['start'] = $updates['startDate'];
        }
        if (isset($updates['dueDate'])) {
            $dates['due'] = $updates['dueDate'];
        }
        if (!empty($dates)) {
            $payload['dates'] = $dates;
        }

        return $this->makeApiRequest(
            $credentials,
            'PUT',
            "/tasks/{$taskId}",
            $payload
        );
    }

    // ========================================
    // COMMENTS API
    // ========================================

    /**
     * Get comments for a task
     *
     * @param array $credentials Wrike credentials
     * @param string $taskId Task ID
     * @return array Comments data
     */
    public function getTaskComments(array $credentials, string $taskId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            "/tasks/{$taskId}/comments"
        );
    }

    /**
     * Add a comment to a task
     *
     * @param array $credentials Wrike credentials
     * @param string $taskId Task ID
     * @param string $text Comment text (supports HTML)
     * @return array Created comment data
     */
    public function addComment(array $credentials, string $taskId, string $text): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'POST',
            "/tasks/{$taskId}/comments",
            ['text' => $text]
        );
    }

    // ========================================
    // FOLDERS API
    // ========================================

    /**
     * List all folders and projects
     *
     * @param array $credentials Wrike credentials
     * @return array Folders data
     */
    public function listFolders(array $credentials): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            '/folders'
        );
    }

    /**
     * Get a folder by ID
     *
     * @param array $credentials Wrike credentials
     * @param string $folderId Folder ID
     * @return array Folder data
     */
    public function getFolder(array $credentials, string $folderId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            "/folders/{$folderId}"
        );
    }

    /**
     * Get all tasks in a folder
     *
     * @param array $credentials Wrike credentials
     * @param string $folderId Folder ID
     * @param int $limit Maximum results
     * @return array Tasks data
     */
    public function getFolderTasks(array $credentials, string $folderId, int $limit = 100): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            "/folders/{$folderId}/tasks",
            null,
            ['pageSize' => min($limit, 1000)]
        );
    }

    // ========================================
    // CONTACTS (USERS) API
    // ========================================

    /**
     * List all contacts (users) in the workspace
     *
     * @param array $credentials Wrike credentials
     * @return array Contacts data
     */
    public function listContacts(array $credentials): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            '/contacts'
        );
    }

    /**
     * Get a contact by ID
     *
     * @param array $credentials Wrike credentials
     * @param string $contactId Contact ID
     * @return array Contact data
     */
    public function getContact(array $credentials, string $contactId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            "/contacts/{$contactId}"
        );
    }

    // ========================================
    // TIME TRACKING API
    // ========================================

    /**
     * Log time to a task
     *
     * @param array $credentials Wrike credentials
     * @param string $taskId Task ID
     * @param int $hours Hours to log
     * @param int $minutes Minutes to log (0-59)
     * @param string|null $comment Timelog comment
     * @param string|null $trackedDate Date tracked (YYYY-MM-DD), defaults to today
     * @return array Created timelog data
     */
    public function logTime(
        array $credentials,
        string $taskId,
        int $hours,
        int $minutes = 0,
        ?string $comment = null,
        ?string $trackedDate = null
    ): array {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'hours' => $hours,
            'minutes' => $minutes,
            'trackedDate' => $trackedDate ?? date('Y-m-d'),
        ];

        if ($comment !== null) {
            $payload['comment'] = $comment;
        }

        return $this->makeApiRequest(
            $credentials,
            'POST',
            "/tasks/{$taskId}/timelogs",
            $payload
        );
    }

    /**
     * Get timelogs for a task
     *
     * @param array $credentials Wrike credentials
     * @param string $taskId Task ID
     * @return array Timelogs data
     */
    public function getTimelogs(array $credentials, string $taskId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            "/tasks/{$taskId}/timelogs"
        );
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Make an API request to Wrike
     *
     * @param array $credentials Wrike credentials
     * @param string $method HTTP method
     * @param string $endpoint API endpoint (relative to base URL)
     * @param array|null $payload Request payload for POST/PUT
     * @param array $queryParams Query parameters for GET requests
     * @return array Response data
     */
    private function makeApiRequest(
        array $credentials,
        string $method,
        string $endpoint,
        ?array $payload = null,
        array $queryParams = []
    ): array {
        $baseUrl = $this->getApiBaseUrl($credentials);

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials['access_token'],
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($queryParams)) {
            $options['query'] = $queryParams;
        }

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request(
                $method,
                $baseUrl . $endpoint,
                $options
            );

            $statusCode = $response->getStatusCode();

            // Handle successful responses
            if ($statusCode >= 200 && $statusCode < 300) {
                // 204 No Content
                if ($statusCode === 204) {
                    return ['success' => true, 'message' => 'Operation completed successfully'];
                }
                return $response->toArray();
            }

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $error = $errorData['error'] ?? 'Unknown error';
            $errorDescription = $errorData['errorDescription'] ?? '';

            $suggestion = $this->getSuggestionForError($statusCode, $error);

            throw new \RuntimeException(
                "Wrike API Error (HTTP {$statusCode}): {$error} - {$errorDescription}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get a helpful suggestion based on the error
     *
     * @param int $statusCode HTTP status code
     * @param string $error Wrike error code
     * @return string Suggestion message
     */
    private function getSuggestionForError(int $statusCode, string $error): string
    {
        $suggestion = '';

        if ($statusCode === 401) {
            $suggestion = ' - Access token expired or invalid. Please reconnect to Wrike.';
        } elseif ($statusCode === 403) {
            $suggestion = ' - Insufficient permissions. Check your Wrike app scopes.';
        } elseif ($statusCode === 404) {
            $suggestion = ' - The requested resource was not found. Verify the ID is correct.';
        } elseif ($statusCode === 429) {
            $suggestion = ' - Rate limit exceeded. Please wait a moment and try again.';
        } elseif ($error === 'invalid_request') {
            $suggestion = ' - Check that all required parameters are provided and values are valid.';
        } elseif ($error === 'resource_not_found') {
            $suggestion = ' - The specified resource does not exist or has been deleted.';
        }

        return $suggestion;
    }
}
