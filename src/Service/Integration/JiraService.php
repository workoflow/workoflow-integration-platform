<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use InvalidArgumentException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;

class JiraService
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {
    }

    private function validateAndNormalizeUrl(string $url): string
    {
        try {
            // Use PSR-7 Uri for proper URL handling
            $uri = new Uri($url);

            // Normalize the URI (removes default ports, duplicate slashes, etc.)
            $uri = UriNormalizer::normalize($uri, UriNormalizer::REMOVE_DEFAULT_PORT | UriNormalizer::REMOVE_DUPLICATE_SLASHES);

            // Remove trailing slash manually
            $path = $uri->getPath();
            if ($path !== '/' && str_ends_with($path, '/')) {
                $uri = $uri->withPath(rtrim($path, '/'));
            }

            // Validate scheme
            $scheme = $uri->getScheme();
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException("Jira URL must use HTTP or HTTPS protocol. Got: '{$scheme}'");
            }

            // Validate host exists
            $host = $uri->getHost();
            if (empty($host)) {
                throw new InvalidArgumentException("Jira URL must include a valid domain name");
            }

            // Require HTTPS for all hosted Atlassian products
            if ($scheme !== 'https') {
                throw new InvalidArgumentException(
                    "Jira URL must use HTTPS for security. Got: '{$url}'. " .
                    "Please use 'https://' instead of '{$scheme}://'"
                );
            }

            // Return the normalized URL as string
            return (string) $uri;
        } catch (\InvalidArgumentException $e) {
            // Re-throw our custom messages
            throw $e;
        } catch (\Throwable $e) {
            // Catch any PSR-7 parsing errors
            throw new InvalidArgumentException(
                "Invalid Jira URL format: '{$url}'. " .
                "Please provide a valid URL like 'https://your-domain.atlassian.net'. " .
                "Error: " . $e->getMessage()
            );
        }
    }

    public function testConnection(array $credentials): bool
    {
        $result = $this->testConnectionDetailed($credentials);
        return $result['success'];
    }

    /**
     * Test Jira connection with detailed error reporting
     *
     * @param array $credentials Jira credentials
     * @return array Detailed test result with success, message, details, and suggestions
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            // Validate and normalize URL
            $url = $this->validateAndNormalizeUrl($credentials['url']);

            // Test authentication endpoint
            try {
                $response = $this->httpClient->request('GET', $url . '/rest/api/3/myself', [
                    'auth_basic' => [$credentials['username'], $credentials['api_token']],
                    'timeout' => 10,
                ]);

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/rest/api/3/myself',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    // Successfully authenticated
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'details' => 'Successfully connected to Jira. API authentication verified.',
                        'suggestion' => '',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    // Non-200 response (should not happen as HttpClient throws exception)
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: Unexpected response from Jira API",
                        'suggestion' => 'Check the error details and verify your Jira instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            /** @phpstan-ignore-next-line catch.neverThrown */
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/rest/api/3/myself',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Invalid email or API token. Please verify your credentials.',
                        'suggestion' => 'Check that your email and API token are correct. Create a new API token at https://id.atlassian.com/manage-profile/security/api-tokens',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'Your API token does not have sufficient permissions.',
                        'suggestion' => 'Ensure the API token has read and write access to Jira projects and issues.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 404) {
                    return [
                        'success' => false,
                        'message' => 'API endpoint not found',
                        'details' => 'The URL may not be a valid Jira instance or the API is not available.',
                        'suggestion' => 'Verify the URL points to a Jira Cloud instance (e.g., https://your-domain.atlassian.net)',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: " . $e->getMessage(),
                        'suggestion' => 'Check the error details and verify your Jira instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach Jira server',
                    'details' => 'Network error: ' . $e->getMessage(),
                    'suggestion' => 'Check the URL and your network connection. Verify the domain is correct and accessible.',
                    'tested_endpoints' => $testedEndpoints
                ];
            }
        } catch (InvalidArgumentException $e) {
            // URL validation error
            return [
                'success' => false,
                'message' => 'Invalid URL',
                'details' => $e->getMessage(),
                'suggestion' => 'Enter a valid Jira URL like https://your-domain.atlassian.net',
                'tested_endpoints' => $testedEndpoints
            ];
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

    public function search(array $credentials, string $jql, int $maxResults = 50): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        // Default to ordering by created date if JQL is empty
        if (empty(trim($jql))) {
            $jql = 'ORDER BY created DESC';
        }

        try {
            // Use /search/jql endpoint (the /search endpoint was deprecated and removed)
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/search/jql', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => [
                    'jql' => $jql,
                    'maxResults' => $maxResults,
                    'fields' => '*all',  // Include all fields (summary, status, assignee, etc.)
                ],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);  // Don't throw on error status codes

            // Extract Jira's error messages
            $errorMessages = $errorData['errorMessages'] ?? [];
            $errors = $errorData['errors'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';

            // Build comprehensive error message
            $errorText = '';
            if (!empty($errorMessages)) {
                $errorText = implode(', ', $errorMessages);
            } elseif (!empty($errors)) {
                $errorText = json_encode($errors);
            } else {
                $errorText = $message;
            }

            // Add context-specific suggestions
            $suggestion = '';
            if (stripos($errorText, 'unbounded') !== false || stripos($errorText, 'restriction') !== false) {
                $suggestion = ' - Add filtering conditions like project, status, or assignee to your JQL query';
            } elseif (stripos($errorText, 'syntax') !== false || stripos($errorText, 'invalid') !== false) {
                $suggestion = ' - Check JQL syntax at https://support.atlassian.com/jira-service-management-cloud/docs/use-advanced-search-with-jira-query-language-jql/';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    public function getIssue(array $credentials, string $issueKey): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/issue/' . $issueKey, [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Verify issue key '{$issueKey}' exists and you have permission to view it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to access this issue';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    public function getSprintsFromBoard(array $credentials, int $boardId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/board/' . $boardId . '/sprint', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => [
                    'startAt' => 0,
                    'maxResults' => 50,
                ],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';

            // Check if error indicates Kanban board (sprints not supported)
            if (
                stripos($errorText, 'sprint') !== false &&
                (stripos($errorText, 'nicht unterstützt') !== false ||
                 stripos($errorText, 'not support') !== false ||
                 stripos($errorText, 'does not support') !== false)
            ) {
                // Try to get board details to confirm it's a Kanban board
                try {
                    $board = $this->getBoard($credentials, $boardId);
                    $boardType = $board['type'] ?? 'unknown';
                    $boardName = $board['name'] ?? "Board {$boardId}";

                    if ($boardType === 'kanban') {
                        throw new \RuntimeException(
                            "This is a Kanban board ('{$boardName}') which does not support sprints. " .
                            "Use jira_get_kanban_issues or jira_get_board_issues instead to get issues from this board.",
                            400
                        );
                    }
                } catch (\RuntimeException $boardError) {
                    // If we can't get board details, re-throw the board error if it's our Kanban message
                    if (stripos($boardError->getMessage(), 'Kanban board') !== false) {
                        throw $boardError;
                    }
                    // Otherwise continue with original error handling below
                }

                $suggestion = ' - This board may be a Kanban board. Kanban boards do not have sprints. Use jira_get_kanban_issues or jira_get_board_issues instead.';
            } elseif ($statusCode === 404 || $statusCode === 400) {
                $suggestion = " - Board ID {$boardId} may not exist or you don't have permission to access it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has Jira Software/Agile permissions';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    public function getSprintIssues(array $credentials, int $sprintId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/sprint/' . $sprintId . '/issue', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => [
                    'maxResults' => 100,
                    'fields' => '*all',
                ],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404 || $statusCode === 400) {
                $suggestion = " - Sprint ID {$sprintId} may not exist or you don't have permission to access it";
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    public function addComment(array $credentials, string $issueKey, string $comment): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        $payload = [
            'body' => [
                'type' => 'doc',
                'version' => 1,
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'text' => $comment,
                                'type' => 'text'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = $this->httpClient->request('POST', $url . '/rest/api/3/issue/' . $issueKey . '/comment', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $payload
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Issue '{$issueKey}' may not exist";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to add comments';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get available transitions for an issue
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key (e.g., PROJ-123)
     * @return array Available transitions with IDs and target statuses
     */
    public function getAvailableTransitions(array $credentials, string $issueKey): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/issue/' . $issueKey . '/transitions', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Issue '{$issueKey}' may not exist or you don't have permission to view it";
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Transition an issue to a new status
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key (e.g., PROJ-123)
     * @param string $transitionId Transition ID (from getAvailableTransitions)
     * @param string|null $comment Optional comment to add during transition
     * @return array Transition response
     */
    public function transitionIssue(array $credentials, string $issueKey, string $transitionId, ?string $comment = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        $payload = [
            'transition' => [
                'id' => $transitionId
            ]
        ];

        // Add comment if provided
        if ($comment !== null) {
            $payload['update'] = [
                'comment' => [
                    [
                        'add' => [
                            'body' => [
                                'type' => 'doc',
                                'version' => 1,
                                'content' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'text' => $comment,
                                                'type' => 'text'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $url . '/rest/api/3/issue/' . $issueKey . '/transitions', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $payload
            ]);

            // Transitions return 204 No Content on success
            $statusCode = $response->getStatusCode();
            if ($statusCode === 204) {
                return ['success' => true, 'message' => 'Issue transitioned successfully'];
            }

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $errors = $errorData['errors'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 400) {
                $suggestion = " - Transition ID '{$transitionId}' may not be valid for this issue's current status";
            } elseif (stripos($errorText, 'required') !== false || !empty($errors)) {
                $suggestion = ' - Some required fields may be missing for this transition';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Transition an issue to a target status by automatically finding the path
     *
     * This method intelligently navigates through JIRA workflows to reach the target status.
     * It will perform multiple transitions if necessary.
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key (e.g., PROJ-123)
     * @param string $targetStatusName Target status name (e.g., "Done", "In Progress")
     * @param string|null $comment Optional comment to add during final transition
     * @param int $maxAttempts Maximum number of transition attempts to prevent infinite loops
     * @return array Result with success status and path taken
     */
    public function transitionIssueToStatus(
        array $credentials,
        string $issueKey,
        string $targetStatusName,
        ?string $comment = null,
        int $maxAttempts = 10
    ): array {
        $url = $this->validateAndNormalizeUrl($credentials['url']);
        $path = [];
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            // Get current issue status
            $issue = $this->getIssue($credentials, $issueKey);
            $currentStatus = $issue['fields']['status']['name'];

            // Check if we've reached the target
            if (strcasecmp($currentStatus, $targetStatusName) === 0) {
                return [
                    'success' => true,
                    'message' => "Issue successfully transitioned to '{$targetStatusName}'",
                    'currentStatus' => $currentStatus,
                    'path' => $path,
                    'attempts' => $attempt - 1
                ];
            }

            // Get available transitions
            $transitions = $this->getAvailableTransitions($credentials, $issueKey);

            if (empty($transitions['transitions'])) {
                return [
                    'success' => false,
                    'message' => "No transitions available from status '{$currentStatus}'",
                    'currentStatus' => $currentStatus,
                    'path' => $path,
                    'attempts' => $attempt
                ];
            }

            // Look for direct transition to target status
            $targetTransition = null;
            foreach ($transitions['transitions'] as $transition) {
                if (strcasecmp($transition['to']['name'], $targetStatusName) === 0) {
                    $targetTransition = $transition;
                    break;
                }
            }

            // If direct transition found, use it
            if ($targetTransition !== null) {
                $transitionComment = ($comment !== null && strcasecmp($targetTransition['to']['name'], $targetStatusName) === 0)
                    ? $comment
                    : null;

                $this->transitionIssue($credentials, $issueKey, $targetTransition['id'], $transitionComment);

                $path[] = [
                    'from' => $currentStatus,
                    'to' => $targetTransition['to']['name'],
                    'transitionId' => $targetTransition['id'],
                    'transitionName' => $targetTransition['name']
                ];

                continue;
            }

            // No direct path found - try first available transition (heuristic approach)
            // In a more sophisticated version, we could implement path finding algorithms
            $firstTransition = $transitions['transitions'][0];

            // Avoid going backwards or loops
            $isLoop = false;
            foreach ($path as $step) {
                if ($step['to'] === $firstTransition['to']['name']) {
                    $isLoop = true;
                    break;
                }
            }

            if ($isLoop) {
                return [
                    'success' => false,
                    'message' => "Cannot find path to '{$targetStatusName}' - workflow loop detected",
                    'currentStatus' => $currentStatus,
                    'targetStatus' => $targetStatusName,
                    'path' => $path,
                    'attempts' => $attempt,
                    'availableTransitions' => array_map(
                        fn($t) => $t['to']['name'],
                        $transitions['transitions']
                    )
                ];
            }

            // Make the transition
            $this->transitionIssue($credentials, $issueKey, $firstTransition['id']);

            $path[] = [
                'from' => $currentStatus,
                'to' => $firstTransition['to']['name'],
                'transitionId' => $firstTransition['id'],
                'transitionName' => $firstTransition['name']
            ];
        }

        // Max attempts reached
        $issue = $this->getIssue($credentials, $issueKey);
        $currentStatus = $issue['fields']['status']['name'];

        return [
            'success' => false,
            'message' => "Could not reach '{$targetStatusName}' after {$maxAttempts} attempts",
            'currentStatus' => $currentStatus,
            'targetStatus' => $targetStatusName,
            'path' => $path,
            'attempts' => $attempt
        ];
    }

    /**
     * Get board details including type (scrum/kanban)
     *
     * @param array $credentials Jira credentials
     * @param int $boardId Board ID
     * @return array Board details with id, name, type (scrum/kanban), and location info
     */
    public function getBoard(array $credentials, int $boardId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/board/' . $boardId, [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Board ID {$boardId} does not exist or you don't have permission to access it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has Jira Software/Agile permissions';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get issues from any board type (Scrum or Kanban)
     * Automatically detects board type and uses appropriate method
     *
     * @param array $credentials Jira credentials
     * @param int $boardId Board ID
     * @param int $maxResults Maximum number of results
     * @return array Issues from the board
     */
    public function getBoardIssues(array $credentials, int $boardId, int $maxResults = 50): array
    {
        // First, get board details to determine type
        $board = $this->getBoard($credentials, $boardId);
        $boardType = $board['type'] ?? 'unknown';

        if ($boardType === 'scrum') {
            // For Scrum boards, get issues from active sprint
            $sprints = $this->getSprintsFromBoard($credentials, $boardId);

            // Find active sprint
            $activeSprint = null;
            foreach ($sprints['values'] ?? [] as $sprint) {
                if (($sprint['state'] ?? '') === 'active') {
                    $activeSprint = $sprint;
                    break;
                }
            }

            if ($activeSprint === null) {
                // No active sprint, return empty result
                return [
                    'expand' => '',
                    'startAt' => 0,
                    'maxResults' => $maxResults,
                    'total' => 0,
                    'issues' => [],
                    'boardType' => 'scrum',
                    'message' => 'No active sprint found on this Scrum board'
                ];
            }

            // Get issues from active sprint
            $result = $this->getSprintIssues($credentials, $activeSprint['id']);
            $result['boardType'] = 'scrum';
            $result['sprintId'] = $activeSprint['id'];
            $result['sprintName'] = $activeSprint['name'];

            return $result;
        } elseif ($boardType === 'kanban') {
            // For Kanban boards, get all board issues
            $result = $this->getKanbanIssues($credentials, $boardId, $maxResults);
            $result['boardType'] = 'kanban';

            return $result;
        } else {
            throw new \RuntimeException(
                "Unknown board type '{$boardType}' for board ID {$boardId}. Expected 'scrum' or 'kanban'."
            );
        }
    }

    /**
     * Get issues from a Kanban board
     *
     * @param array $credentials Jira credentials
     * @param int $boardId Kanban board ID
     * @param int $maxResults Maximum number of results
     * @return array Issues from the Kanban board
     */
    public function getKanbanIssues(array $credentials, int $boardId, int $maxResults = 50): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            // Use the Jira Agile API to get all issues on the board
            // This endpoint works for both Kanban and Scrum boards
            $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/board/' . $boardId . '/issue', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => [
                    'maxResults' => $maxResults,
                    'fields' => '*all',
                ],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404 || $statusCode === 400) {
                $suggestion = " - Board ID {$boardId} may not exist, may not be a Kanban board, or you don't have permission to access it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has Jira Software/Agile permissions';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }
}
