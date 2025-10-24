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
                    // Successfully authenticated, now test permissions endpoint
                    try {
                        $permResponse = $this->httpClient->request('GET', $url . '/rest/api/3/mypermissions', [
                            'auth_basic' => [$credentials['username'], $credentials['api_token']],
                            'timeout' => 10,
                        ]);

                        $permStatusCode = $permResponse->getStatusCode();
                        $testedEndpoints[] = [
                            'endpoint' => '/rest/api/3/mypermissions',
                            'status' => $permStatusCode === 200 ? 'success' : 'failed',
                            'http_code' => $permStatusCode
                        ];

                        if ($permStatusCode === 200) {
                            return [
                                'success' => true,
                                'message' => 'Connection successful',
                                'details' => 'Successfully connected to Jira. API authentication and permissions check passed.',
                                'suggestion' => '',
                                'tested_endpoints' => $testedEndpoints
                            ];
                        } else {
                            return [
                                'success' => false,
                                'message' => 'Permissions check failed',
                                'details' => "Authentication works but permissions check returned HTTP {$permStatusCode}.",
                                'suggestion' => 'Contact your Jira administrator to verify API access permissions.',
                                'tested_endpoints' => $testedEndpoints
                            ];
                        }
                    /** @phpstan-ignore-next-line catch.neverThrown */
                    } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                        $permStatusCode = $e->getResponse()->getStatusCode();
                        $testedEndpoints[] = [
                            'endpoint' => '/rest/api/3/mypermissions',
                            'status' => 'failed',
                            'http_code' => $permStatusCode
                        ];

                        return [
                            'success' => false,
                            'message' => 'Permissions check access denied',
                            'details' => "Authentication works but permissions check returned HTTP {$permStatusCode}.",
                            'suggestion' => 'Ensure your API token has sufficient permissions to access Jira APIs.',
                            'tested_endpoints' => $testedEndpoints
                        ];
                    }
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
        $response = $this->httpClient->request('GET', $url . '/rest/api/3/search/jql', [
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
        $url = $this->validateAndNormalizeUrl($credentials['url']);
        $response = $this->httpClient->request('GET', $url . '/rest/api/3/issue/' . $issueKey, [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ]);

        return $response->toArray();
    }

    public function getSprintsFromBoard(array $credentials, int $boardId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);
        $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/board/' . $boardId . '/sprint', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ]);

        return $response->toArray();
    }

    public function getSprintIssues(array $credentials, int $sprintId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);
        $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/sprint/' . $sprintId . '/issue', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ]);

        return $response->toArray();
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

        $response = $this->httpClient->request('POST', $url . '/rest/api/3/issue/' . $issueKey . '/comment', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'json' => $payload
        ]);

        return $response->toArray();
    }
}
