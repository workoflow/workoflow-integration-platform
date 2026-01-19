<?php

namespace App\Service\Integration;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for SAP Analytics Cloud (SAC) API integration.
 *
 * Uses two-legged OAuth 2.0 (Client Credentials Grant) for authentication.
 * Token endpoint is derived from tenant URL.
 */
class SapSacService
{
    private const TOKEN_REFRESH_BUFFER = 300; // 5 minutes buffer before expiry

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Exchange SAML2 assertion for SAP SAC OAuth2 access token (User Delegation).
     *
     * Uses the OAuth 2.0 SAML 2.0 Bearer Assertion flow (RFC 7522).
     * This enables user delegation: API calls are attributed to the user.
     *
     * @param string $saml2Assertion Base64-encoded SAML2 assertion from Azure AD
     * @param string $sacTenantUrl SAC tenant URL (e.g., https://company.eu10.hcs.cloud.sap)
     * @param string $sacClientId SAC OAuth Client ID (from SAC App Integration)
     * @param string $sacClientSecret SAC OAuth Client Secret
     * @return array Token result with success, access_token, or error
     */
    public function exchangeSaml2ForSacToken(
        string $saml2Assertion,
        string $sacTenantUrl,
        string $sacClientId,
        string $sacClientSecret
    ): array {
        // Build the token URL from the SAC tenant URL
        $tokenUrl = $this->buildTokenUrl($sacTenantUrl);

        $this->logger->info('SAP SAC OAuth: Starting SAML2 to SAC token exchange', [
            'sac_tenant_url' => $sacTenantUrl,
            'token_url' => $tokenUrl,
        ]);

        try {
            $response = $this->httpClient->request('POST', $tokenUrl, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("{$sacClientId}:{$sacClientSecret}"),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:saml2-bearer',
                    'client_id' => $sacClientId,
                    'assertion' => base64_encode($saml2Assertion),
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            if (!isset($data['access_token'])) {
                $this->logger->error('SAP SAC OAuth: No access_token in response', [
                    'response' => $data,
                ]);
                return [
                    'success' => false,
                    'error' => 'No access_token in SAC OAuth response',
                ];
            }

            $this->logger->info('SAP SAC OAuth: Successfully obtained SAC access token');

            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'token_type' => $data['token_type'] ?? 'Bearer',
                'expires_in' => $data['expires_in'] ?? 3600,
            ];
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessage = $errorData['error_description']
                ?? $errorData['error']
                ?? "OAuth failed with HTTP {$statusCode}";

            $this->logger->error('SAP SAC OAuth: Token exchange failed', [
                'status' => $statusCode,
                'error' => $errorMessage,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
                'http_status' => $statusCode,
            ];
        } catch (\Exception $e) {
            $this->logger->error('SAP SAC OAuth: Exception during token exchange', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Token exchange failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Ensure we have a valid access token for user delegation mode.
     *
     * For user delegation, this method checks if there's a cached SAC token
     * and returns it, otherwise indicates token exchange is needed.
     *
     * @param array $credentials Credentials including cached SAC token info
     * @return array Updated credentials with valid token or indicator to exchange
     */
    public function ensureValidUserDelegationToken(array $credentials): array
    {
        // Check if we have a cached SAC access token that's still valid
        $sacExpiresAt = $credentials['sac_expires_at'] ?? 0;

        if (
            !empty($credentials['sac_access_token'])
            && time() < ($sacExpiresAt - self::TOKEN_REFRESH_BUFFER)
        ) {
            // Token is still valid
            return $credentials;
        }

        // Token expired or doesn't exist - indicate exchange is needed
        return array_merge($credentials, [
            'needs_token_exchange' => true,
        ]);
    }

    /**
     * Test SAP SAC connection with detailed error reporting
     *
     * @param array $credentials SAC credentials (tenant_url, client_id, client_secret)
     * @return array Detailed test result with success, message, details, and suggestions
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            // First, try to get an access token
            $tokenResult = $this->getAccessToken($credentials);
            if (!$tokenResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed',
                    'details' => $tokenResult['error'] ?? 'Could not obtain access token',
                    'suggestion' => 'Verify your Client ID and Client Secret from SAC App Integration.',
                    'tested_endpoints' => $testedEndpoints
                ];
            }

            $accessToken = $tokenResult['access_token'];
            $tenantUrl = rtrim($credentials['tenant_url'], '/');

            // Test API endpoint - list providers (models)
            try {
                $response = $this->httpClient->request(
                    'GET',
                    $tenantUrl . '/api/v1/dataexport/administration/Namespaces(NamespaceID=\'sac\')/Providers/',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                            'Accept' => 'application/json',
                        ],
                        'timeout' => 15,
                    ]
                );

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/api/v1/dataexport/administration/.../Providers/',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    $data = $response->toArray();
                    $providerCount = count($data['value'] ?? []);
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'details' => "Successfully connected to SAP SAC. Found {$providerCount} data model(s).",
                        'suggestion' => '',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'API access failed',
                        'details' => "HTTP {$statusCode}: Unexpected response from SAP SAC API",
                        'suggestion' => 'Check that the OAuth client has Data Export API permissions.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            /** @phpstan-ignore-next-line catch.neverThrown */
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/api/v1/dataexport/administration/.../Providers/',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Access token was rejected. OAuth configuration may be incorrect.',
                        'suggestion' => 'Verify the OAuth client has correct scopes in SAC App Integration.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'OAuth client does not have API access permissions.',
                        'suggestion' => 'Enable Data Export API access in SAC App Integration.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'API access failed',
                        'details' => "HTTP {$statusCode}: " . $e->getMessage(),
                        'suggestion' => 'Check the error details and verify SAC configuration.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach SAP SAC API',
                    'details' => 'Network error: ' . $e->getMessage(),
                    'suggestion' => 'Check your network connection and tenant URL.',
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
     * Get OAuth2 access token using Client Credentials Grant
     *
     * @param array $credentials Credentials with tenant_url, client_id, client_secret
     * @return array Token result with access_token, expires_at, or error
     */
    public function getAccessToken(array $credentials): array
    {
        $tokenUrl = $this->buildTokenUrl($credentials['tenant_url']);

        try {
            $response = $this->httpClient->request(
                'POST',
                $tokenUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'body' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $credentials['client_id'],
                        'client_secret' => $credentials['client_secret'],
                    ],
                    'timeout' => 15,
                ]
            );

            $data = $response->toArray();

            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'expires_at' => time() + ($data['expires_in'] ?? 3600),
                'token_type' => $data['token_type'] ?? 'Bearer',
            ];
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $this->logger->error('SAP SAC OAuth failed', [
                'status' => $statusCode,
                'error' => $errorData['error'] ?? 'unknown',
                'description' => $errorData['error_description'] ?? ''
            ]);

            return [
                'success' => false,
                'error' => $errorData['error_description']
                    ?? $errorData['error']
                    ?? "OAuth failed with HTTP {$statusCode}",
            ];
        } catch (\Exception $e) {
            $this->logger->error('SAP SAC OAuth exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to connect to OAuth endpoint: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Ensure we have a valid access token, refreshing if needed
     *
     * @param array $credentials Credentials including cached token info
     * @return array Updated credentials with valid token
     */
    public function ensureValidToken(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? 0;

        // Check if token needs refresh (with 5 minute buffer)
        if (time() >= ($expiresAt - self::TOKEN_REFRESH_BUFFER)) {
            $this->logger->info('SAP SAC access token expiring soon, refreshing...');
            $tokenResult = $this->getAccessToken($credentials);

            if ($tokenResult['success']) {
                $credentials['access_token'] = $tokenResult['access_token'];
                $credentials['expires_at'] = $tokenResult['expires_at'];
            } else {
                throw new \RuntimeException(
                    'Failed to refresh SAP SAC token: ' . ($tokenResult['error'] ?? 'Unknown error')
                );
            }
        }

        return $credentials;
    }

    /**
     * Build OAuth token URL from tenant URL
     *
     * Example:
     *   Input: https://valantic.eu10.hcs.cloud.sap
     *   Output: https://valantic.authentication.eu10.hana.ondemand.com/oauth/token
     *
     * @param string $tenantUrl SAC tenant URL
     * @return string OAuth token endpoint URL
     */
    private function buildTokenUrl(string $tenantUrl): string
    {
        // Parse the tenant URL
        $parsed = parse_url($tenantUrl);
        $host = $parsed['host'] ?? '';

        // Expected format: {tenant}.{region}.hcs.cloud.sap or {tenant}.{region}.sapanalytics.cloud
        // We need: {tenant}.authentication.{region}.hana.ondemand.com
        if (preg_match('/^([^.]+)\.([^.]+)\.(hcs\.cloud\.sap|sapanalytics\.cloud)$/', $host, $matches)) {
            $tenant = $matches[1];
            $region = $matches[2];
            return "https://{$tenant}.authentication.{$region}.hana.ondemand.com/oauth/token";
        }

        // Alternative format: direct auth URL construction
        if (preg_match('/^([^.]+)\.([^.]+)\./', $host, $matches)) {
            $tenant = $matches[1];
            $region = $matches[2];
            return "https://{$tenant}.authentication.{$region}.hana.ondemand.com/oauth/token";
        }

        throw new \InvalidArgumentException(
            'Cannot determine OAuth endpoint from tenant URL. Expected format: https://{tenant}.{region}.hcs.cloud.sap'
        );
    }

    // ========================================
    // MODEL DISCOVERY API
    // ========================================

    /**
     * List available analytics models/providers
     *
     * @param array $credentials SAC credentials
     * @return array List of models with their IDs and metadata
     */
    public function listModels(array $credentials): array
    {
        $credentials = $this->ensureValidToken($credentials);
        $tenantUrl = rtrim($credentials['tenant_url'], '/');

        $response = $this->makeApiRequest(
            $credentials,
            'GET',
            $tenantUrl . '/api/v1/dataexport/administration/Namespaces(NamespaceID=\'sac\')/Providers/'
        );

        $models = [];
        foreach ($response['value'] ?? [] as $provider) {
            $models[] = [
                'id' => $provider['ProviderID'] ?? $provider['ModelID'] ?? null,
                'name' => $provider['ProviderName'] ?? $provider['Name'] ?? $provider['Description'] ?? null,
                'description' => $provider['Description'] ?? null,
                'namespace' => 'sac',
            ];
        }

        return [
            'models' => $models,
            'count' => count($models),
        ];
    }

    /**
     * Get metadata (dimensions and measures) for a specific model
     *
     * @param array $credentials SAC credentials
     * @param string $modelId Model/Provider ID
     * @return array Model metadata including dimensions and measures
     */
    public function getModelMetadata(array $credentials, string $modelId): array
    {
        $credentials = $this->ensureValidToken($credentials);
        $tenantUrl = rtrim($credentials['tenant_url'], '/');

        // Get OData metadata for the model
        $response = $this->makeApiRequest(
            $credentials,
            'GET',
            $tenantUrl . "/api/v1/dataexport/providers/sac/{$modelId}/\$metadata",
            null,
            [],
            'application/xml'
        );

        return $this->parseModelMetadata($response, $modelId);
    }

    /**
     * Parse OData metadata XML to extract dimensions and measures
     *
     * @param string $xmlContent Raw XML metadata
     * @param string $modelId Model ID for context
     * @return array Parsed metadata with dimensions and measures
     */
    private function parseModelMetadata(string $xmlContent, string $modelId): array
    {
        $dimensions = [];
        $measures = [];

        try {
            $xml = new \SimpleXMLElement($xmlContent);
            $xml->registerXPathNamespace('edmx', 'http://schemas.microsoft.com/ado/2007/06/edmx');
            $xml->registerXPathNamespace('edm', 'http://schemas.microsoft.com/ado/2008/09/edm');

            // Find EntityType definitions
            $entityTypes = $xml->xpath('//edm:EntityType');
            foreach ($entityTypes as $entityType) {
                $typeName = (string) $entityType['Name'];

                // Check properties
                foreach ($entityType->Property as $property) {
                    $propertyName = (string) $property['Name'];
                    $propertyType = (string) $property['Type'];

                    // Dimension master data types typically end with "Master"
                    if (str_ends_with($typeName, 'Master') || str_contains($typeName, 'Dimension')) {
                        $dimensions[$propertyName] = [
                            'name' => $propertyName,
                            'type' => $propertyType,
                            'entity_type' => $typeName,
                        ];
                    } elseif ($typeName === 'MasterData' || $typeName === 'FactData') {
                        // Determine if it's a measure or dimension based on type
                        if (in_array($propertyType, ['Edm.Decimal', 'Edm.Double', 'Edm.Int32', 'Edm.Int64'])) {
                            $measures[$propertyName] = [
                                'name' => $propertyName,
                                'type' => $propertyType,
                                'entity_type' => $typeName,
                            ];
                        } else {
                            $dimensions[$propertyName] = [
                                'name' => $propertyName,
                                'type' => $propertyType,
                                'entity_type' => $typeName,
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to parse SAC model metadata: ' . $e->getMessage());
        }

        return [
            'model_id' => $modelId,
            'dimensions' => array_values($dimensions),
            'measures' => array_values($measures),
            'dimension_count' => count($dimensions),
            'measure_count' => count($measures),
        ];
    }

    // ========================================
    // DATA QUERY API
    // ========================================

    /**
     * Query fact data from a model with optional filters
     *
     * @param array $credentials SAC credentials
     * @param string $modelId Model/Provider ID
     * @param string|null $filter OData filter expression
     * @param string|null $select Comma-separated list of properties to return
     * @param int $top Maximum results to return
     * @param int $skip Number of results to skip
     * @return array Query results
     */
    public function queryModelData(
        array $credentials,
        string $modelId,
        ?string $filter = null,
        ?string $select = null,
        int $top = 100,
        int $skip = 0
    ): array {
        $credentials = $this->ensureValidToken($credentials);
        $tenantUrl = rtrim($credentials['tenant_url'], '/');

        $queryParams = [
            '$top' => $top,
            '$skip' => $skip,
            '$format' => 'json',
        ];

        if ($filter) {
            $queryParams['$filter'] = $filter;
        }

        if ($select) {
            $queryParams['$select'] = $select;
        }

        $response = $this->makeApiRequest(
            $credentials,
            'GET',
            $tenantUrl . "/api/v1/dataexport/providers/sac/{$modelId}/MasterData",
            null,
            $queryParams
        );

        $results = $response['value'] ?? $response['d']['results'] ?? [];

        return [
            'data' => $results,
            'count' => count($results),
            'model_id' => $modelId,
            'filter' => $filter,
            'has_more' => count($results) === $top,
            'next_skip' => $skip + count($results),
        ];
    }

    /**
     * Get members/values of a specific dimension
     *
     * @param array $credentials SAC credentials
     * @param string $modelId Model/Provider ID
     * @param string $dimension Dimension name
     * @param int $top Maximum results
     * @param int $skip Number to skip for pagination
     * @return array Dimension members
     */
    public function getDimensionMembers(
        array $credentials,
        string $modelId,
        string $dimension,
        int $top = 100,
        int $skip = 0
    ): array {
        $credentials = $this->ensureValidToken($credentials);
        $tenantUrl = rtrim($credentials['tenant_url'], '/');

        $queryParams = [
            '$top' => $top,
            '$skip' => $skip,
            '$format' => 'json',
        ];

        // Dimension master data endpoint typically uses {Dimension}Master
        $response = $this->makeApiRequest(
            $credentials,
            'GET',
            $tenantUrl . "/api/v1/dataexport/providers/sac/{$modelId}/{$dimension}Master",
            null,
            $queryParams
        );

        $members = $response['value'] ?? $response['d']['results'] ?? [];

        return [
            'dimension' => $dimension,
            'model_id' => $modelId,
            'members' => $members,
            'count' => count($members),
            'has_more' => count($members) === $top,
            'next_skip' => $skip + count($members),
        ];
    }

    // ========================================
    // STORY API
    // ========================================

    /**
     * List available stories (dashboards/reports)
     *
     * @param array $credentials SAC credentials
     * @param int $top Maximum results
     * @param int $skip Number to skip
     * @return array List of stories
     */
    public function listStories(array $credentials, int $top = 50, int $skip = 0): array
    {
        $credentials = $this->ensureValidToken($credentials);
        $tenantUrl = rtrim($credentials['tenant_url'], '/');

        $queryParams = [
            '$top' => $top,
            '$skip' => $skip,
        ];

        $response = $this->makeApiRequest(
            $credentials,
            'GET',
            $tenantUrl . '/api/v1/stories',
            null,
            $queryParams
        );

        $stories = [];
        foreach ($response['value'] ?? $response as $story) {
            $stories[] = [
                'id' => $story['id'] ?? $story['storyId'] ?? null,
                'name' => $story['name'] ?? $story['storyName'] ?? null,
                'description' => $story['description'] ?? null,
                'created_time' => $story['createdTime'] ?? $story['created'] ?? null,
                'modified_time' => $story['modifiedTime'] ?? $story['modified'] ?? null,
            ];
        }

        return [
            'stories' => $stories,
            'count' => count($stories),
            'has_more' => count($stories) === $top,
            'next_skip' => $skip + count($stories),
        ];
    }

    /**
     * Get details of a specific story
     *
     * @param array $credentials SAC credentials
     * @param string $storyId Story ID
     * @return array Story details including associated models
     */
    public function getStory(array $credentials, string $storyId): array
    {
        $credentials = $this->ensureValidToken($credentials);
        $tenantUrl = rtrim($credentials['tenant_url'], '/');

        $response = $this->makeApiRequest(
            $credentials,
            'GET',
            $tenantUrl . "/api/v1/stories/{$storyId}"
        );

        return [
            'id' => $response['id'] ?? $response['storyId'] ?? $storyId,
            'name' => $response['name'] ?? $response['storyName'] ?? null,
            'description' => $response['description'] ?? null,
            'models' => $response['models'] ?? $response['dataProviders'] ?? [],
            'created_time' => $response['createdTime'] ?? $response['created'] ?? null,
            'modified_time' => $response['modifiedTime'] ?? $response['modified'] ?? null,
            'raw' => $response,
        ];
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Make an API request to SAP SAC
     *
     * @param array $credentials SAC credentials with access_token
     * @param string $method HTTP method
     * @param string $url Full API URL
     * @param array|null $payload Request payload for POST
     * @param array $queryParams Query parameters
     * @param string $accept Accept header value
     * @return array|string Response data (array for JSON, string for XML)
     */
    private function makeApiRequest(
        array $credentials,
        string $method,
        string $url,
        ?array $payload = null,
        array $queryParams = [],
        string $accept = 'application/json'
    ): array|string {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials['access_token'],
                'Accept' => $accept,
            ],
            'timeout' => 30,
        ];

        if (!empty($queryParams)) {
            $options['query'] = $queryParams;
        }

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $content = $response->getContent();

                // Return raw content for XML
                if ($accept === 'application/xml' || str_contains($accept, 'xml')) {
                    return $content;
                }

                // Parse JSON
                return json_decode($content, true) ?? [];
            }

            throw new \RuntimeException("SAP SAC API returned HTTP {$statusCode}");
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            $errorMessage = $this->extractErrorMessage($content, $statusCode);
            $suggestion = $this->getSuggestionForError($statusCode, $errorMessage);

            throw new \RuntimeException(
                "SAP SAC API Error (HTTP {$statusCode}): {$errorMessage}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Extract error message from API response
     *
     * @param string $content Response content
     * @param int $statusCode HTTP status code
     * @return string Error message
     */
    private function extractErrorMessage(string $content, int $statusCode): string
    {
        // Try JSON
        $json = json_decode($content, true);
        if ($json) {
            return $json['error']['message']['value']
                ?? $json['error']['message']
                ?? $json['error']
                ?? $json['message']
                ?? "HTTP {$statusCode}";
        }

        // Try extracting from XML
        if (str_contains($content, '<message>')) {
            preg_match('/<message[^>]*>([^<]+)<\/message>/i', $content, $matches);
            if (!empty($matches[1])) {
                return $matches[1];
            }
        }

        return "HTTP {$statusCode}: " . substr($content, 0, 200);
    }

    /**
     * Get helpful suggestion based on error
     *
     * @param int $statusCode HTTP status code
     * @param string $errorMessage Error message
     * @return string Suggestion
     */
    private function getSuggestionForError(int $statusCode, string $errorMessage): string
    {
        if ($statusCode === 401) {
            return ' - Access token invalid or expired. Check OAuth client configuration.';
        }
        if ($statusCode === 403) {
            return ' - Insufficient permissions. Verify OAuth client has Data Export API access.';
        }
        if ($statusCode === 404) {
            return ' - Resource not found. Verify model ID or endpoint path.';
        }
        if ($statusCode === 429) {
            return ' - Rate limit exceeded. Please wait and try again.';
        }
        if (stripos($errorMessage, 'provider') !== false) {
            return ' - Model/provider may not exist or is not accessible.';
        }

        return '';
    }
}
