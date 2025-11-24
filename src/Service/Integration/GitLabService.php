<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use InvalidArgumentException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;

class GitLabService
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
                throw new InvalidArgumentException("GitLab URL must use HTTP or HTTPS protocol. Got: '{$scheme}'");
            }

            // Validate host exists
            $host = $uri->getHost();
            if (empty($host)) {
                throw new InvalidArgumentException("GitLab URL must include a valid domain name");
            }

            // Require HTTPS for gitlab.com
            if ($scheme !== 'https' && str_contains($host, 'gitlab.com')) {
                throw new InvalidArgumentException(
                    "GitLab URL must use HTTPS for security. Got: '{$url}'. " .
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
                "Invalid GitLab URL format: '{$url}'. " .
                "Please provide a valid URL like 'https://gitlab.com' or 'https://gitlab.example.com'. " .
                "Error: " . $e->getMessage()
            );
        }
    }

    /**
     * Build authorization headers for GitLab API
     */
    private function getAuthHeaders(string $apiToken): array
    {
        return [
            'PRIVATE-TOKEN' => $apiToken,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Parse project parameter to get project ID or path
     * Supports: numeric ID, "namespace/project", or URL-encoded path
     */
    private function parseProjectParameter(string $project): string
    {
        // If numeric ID, return as-is
        if (is_numeric($project)) {
            return $project;
        }

        // URL-encode the project path
        return urlencode($project);
    }

    public function testConnection(array $credentials): bool
    {
        $result = $this->testConnectionDetailed($credentials);
        return $result['success'];
    }

    /**
     * Test GitLab connection with detailed error reporting
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            // Validate and normalize URL
            $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);

            // Test authentication endpoint
            try {
                $response = $this->httpClient->request('GET', $url . '/api/v4/user', [
                    'headers' => $this->getAuthHeaders($credentials['api_token']),
                    'timeout' => 10,
                ]);

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/api/v4/user',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    // Successfully authenticated
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'details' => 'Successfully connected to GitLab. API authentication verified.',
                        'suggestion' => '',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    // Non-200 response (should not happen as HttpClient throws exception)
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: Unexpected response from GitLab API",
                        'suggestion' => 'Check the error details and verify your GitLab instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            /** @phpstan-ignore-next-line catch.neverThrown */
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/api/v4/user',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Invalid API token. Please verify your credentials.',
                        'suggestion' => 'Check that your API token is correct. Create a new API token in GitLab under User Settings > Access Tokens',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'Your API token does not have sufficient permissions.',
                        'suggestion' => 'Ensure the API token has api, read_api, read_repository, and write_repository scopes.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 404) {
                    return [
                        'success' => false,
                        'message' => 'API endpoint not found',
                        'details' => 'The URL may not be a valid GitLab instance or the API is not available.',
                        'suggestion' => 'Verify the URL points to a GitLab instance (e.g., https://gitlab.com or https://gitlab.example.com)',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: " . $e->getMessage(),
                        'suggestion' => 'Check the error details and verify your GitLab instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach GitLab server',
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
                'suggestion' => 'Enter a valid GitLab URL like https://gitlab.com or https://gitlab.example.com',
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

    // Repository & Metadata Tools

    public function getProject(array $credentials, string $project): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function listProjects(array $credentials, ?bool $membership = null, int $perPage = 20): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $query = ['per_page' => $perPage];

        if ($membership !== null) {
            $query['membership'] = $membership ? 'true' : 'false';
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getFileContent(array $credentials, string $project, string $filePath, ?string $ref = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);
        $encodedPath = urlencode($filePath);

        $query = [];
        if ($ref !== null) {
            $query['ref'] = $ref;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/files/' . $encodedPath, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function listRepositoryTree(array $credentials, string $project, ?string $path = null, ?string $ref = null, bool $recursive = false): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = ['recursive' => $recursive ? 'true' : 'false'];
        if ($path !== null) {
            $query['path'] = $path;
        }
        if ($ref !== null) {
            $query['ref'] = $ref;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/tree', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    // Tags, Branches & Commits

    public function listTags(array $credentials, string $project): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/tags', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function listBranches(array $credentials, string $project): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/branches', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getCommit(array $credentials, string $project, string $sha): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/commits/' . $sha, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function listCommits(array $credentials, string $project, ?string $refName = null, int $perPage = 20): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = ['per_page' => $perPage];
        if ($refName !== null) {
            $query['ref_name'] = $refName;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/commits', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    // Merge Requests (Read)

    public function searchMergeRequests(array $credentials, string $project, ?string $state = null, ?string $scope = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($state !== null) {
            $query['state'] = $state;
        }
        if ($scope !== null) {
            $query['scope'] = $scope;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getMergeRequest(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getMergeRequestChanges(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/changes', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getMergeRequestDiscussions(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/discussions', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getMergeRequestCommits(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/commits', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    // Merge Requests (Write)

    public function createMergeRequest(array $credentials, string $project, string $sourceBranch, string $targetBranch, string $title, ?string $description = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = [
            'source_branch' => $sourceBranch,
            'target_branch' => $targetBranch,
            'title' => $title,
        ];

        if ($description !== null) {
            $payload['description'] = $description;
        }

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/merge_requests', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function updateMergeRequest(array $credentials, string $project, int $mergeRequestIid, ?string $title = null, ?string $description = null, ?string $stateEvent = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = [];
        if ($title !== null) {
            $payload['title'] = $title;
        }
        if ($description !== null) {
            $payload['description'] = $description;
        }
        if ($stateEvent !== null) {
            $payload['state_event'] = $stateEvent;
        }

        $response = $this->httpClient->request('PUT', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function addMergeRequestNote(array $credentials, string $project, int $mergeRequestIid, string $body): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['body' => $body];

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/notes', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    // Issues

    public function searchIssues(array $credentials, string $project, ?string $state = null, ?string $scope = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($state !== null) {
            $query['state'] = $state;
        }
        if ($scope !== null) {
            $query['scope'] = $scope;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/issues', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getIssue(array $credentials, string $project, int $issueIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/issues/' . $issueIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function createIssue(array $credentials, string $project, string $title, ?string $description = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['title' => $title];
        if ($description !== null) {
            $payload['description'] = $description;
        }

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/issues', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function updateIssue(array $credentials, string $project, int $issueIid, ?string $title = null, ?string $description = null, ?string $stateEvent = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = [];
        if ($title !== null) {
            $payload['title'] = $title;
        }
        if ($description !== null) {
            $payload['description'] = $description;
        }
        if ($stateEvent !== null) {
            $payload['state_event'] = $stateEvent;
        }

        $response = $this->httpClient->request('PUT', $url . '/api/v4/projects/' . $projectId . '/issues/' . $issueIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    // Packages

    public function searchPackages(array $credentials, string $project, ?string $packageName = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($packageName !== null) {
            $query['package_name'] = $packageName;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/packages', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getPackage(array $credentials, string $project, int $packageId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/packages/' . $packageId, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    // CI/CD

    public function listPipelines(array $credentials, string $project, ?string $ref = null, ?string $status = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($ref !== null) {
            $query['ref'] = $ref;
        }
        if ($status !== null) {
            $query['status'] = $status;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/pipelines', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getPipeline(array $credentials, string $project, int $pipelineId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/pipelines/' . $pipelineId, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getPipelineJobs(array $credentials, string $project, int $pipelineId, ?array $scope = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($scope !== null) {
            $query['scope'] = $scope;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/pipelines/' . $pipelineId . '/jobs', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getJobTrace(array $credentials, string $project, int $jobId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/jobs/' . $jobId . '/trace', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        // Note: This returns plain text, not JSON
        return [
            'job_id' => $jobId,
            'trace' => $response->getContent()
        ];
    }

    public function getJob(array $credentials, string $project, int $jobId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/jobs/' . $jobId, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }
}
