<?php

namespace App\Service\Integration;

use App\Entity\IntegrationConfig;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HubSpotService
{
    private const API_BASE_URL = 'https://api.hubapi.com';
    private const TOKEN_REFRESH_BUFFER = 300; // 5 minutes buffer before expiry

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private EncryptionService $encryptionService
    ) {
    }

    /**
     * Test HubSpot connection with detailed error reporting
     *
     * @param array $credentials HubSpot credentials (OAuth tokens)
     * @return array Detailed test result with success, message, details, and suggestions
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            // Ensure we have a valid token
            $credentials = $this->ensureValidToken($credentials);

            // Test authentication endpoint - get account info
            try {
                $response = $this->httpClient->request(
                    'GET',
                    self::API_BASE_URL . '/account-info/v3/details',
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
                    'endpoint' => '/account-info/v3/details',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    $data = $response->toArray();
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'details' => 'Successfully connected to HubSpot. Account: ' . ($data['portalId'] ?? 'Unknown'),
                        'suggestion' => '',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: Unexpected response from HubSpot API",
                        'suggestion' => 'Check the error details and verify your HubSpot access.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            /** @phpstan-ignore-next-line catch.neverThrown */
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/account-info/v3/details',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Invalid or expired access token. Please reconnect to HubSpot.',
                        'suggestion' => 'Click "Reconnect" to refresh your HubSpot authorization.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'Your HubSpot app does not have sufficient permissions.',
                        'suggestion' => 'Ensure the app has the required scopes: crm.objects.contacts, crm.objects.companies, crm.objects.deals',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: " . $e->getMessage(),
                        'suggestion' => 'Check the error details and verify your HubSpot access.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach HubSpot API',
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
            $this->logger->info('HubSpot access token expiring soon, refreshing...');
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
            throw new \RuntimeException('No refresh token available. Please reconnect to HubSpot.');
        }

        $clientId = $_ENV['HUBSPOT_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['HUBSPOT_CLIENT_SECRET'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('HubSpot OAuth credentials not configured.');
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                'https://api.hubapi.com/oauth/v1/token',
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

            // Update credentials
            $credentials['access_token'] = $data['access_token'];
            $credentials['refresh_token'] = $data['refresh_token'] ?? $credentials['refresh_token'];
            $credentials['expires_at'] = time() + ($data['expires_in'] ?? 1800);

            // Persist updated tokens if config provided
            if ($config !== null) {
                $config->setEncryptedCredentials(
                    $this->encryptionService->encrypt(json_encode($credentials))
                );
                $this->entityManager->flush();
                $this->logger->info('HubSpot tokens refreshed and persisted.');
            }

            return $credentials;
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $this->logger->error('Failed to refresh HubSpot token: ' . $e->getMessage());
            throw new \RuntimeException(
                'Failed to refresh HubSpot access token. Please reconnect to HubSpot.',
                0,
                $e
            );
        }
    }

    // ========================================
    // CONTACTS API
    // ========================================

    /**
     * Search for contacts using HubSpot search API
     *
     * @param array $credentials HubSpot credentials
     * @param string $query Search query (searches across standard properties)
     * @param int $limit Maximum results to return
     * @return array Search results
     */
    public function searchContacts(array $credentials, string $query, int $limit = 20): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'query' => $query,
            'limit' => $limit,
            'properties' => [
                'firstname',
                'lastname',
                'email',
                'phone',
                'company',
                'jobtitle',
                'lifecyclestage',
                'hs_lead_status',
                'createdate',
                'lastmodifieddate'
            ]
        ];

        return $this->makeApiRequest(
            $credentials,
            'POST',
            '/crm/v3/objects/contacts/search',
            $payload
        );
    }

    /**
     * Get a contact by ID
     *
     * @param array $credentials HubSpot credentials
     * @param string $contactId Contact ID
     * @return array Contact data
     */
    public function getContact(array $credentials, string $contactId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            '/crm/v3/objects/contacts/' . $contactId,
            null,
            [
                'properties' => 'firstname,lastname,email,phone,company,jobtitle,lifecyclestage,hs_lead_status,createdate,lastmodifieddate,notes_last_updated,hs_object_id'
            ]
        );
    }

    /**
     * Create a new contact
     *
     * @param array $credentials HubSpot credentials
     * @param array $properties Contact properties
     * @return array Created contact data
     */
    public function createContact(array $credentials, array $properties): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'properties' => $properties
        ];

        return $this->makeApiRequest(
            $credentials,
            'POST',
            '/crm/v3/objects/contacts',
            $payload
        );
    }

    /**
     * Update an existing contact
     *
     * @param array $credentials HubSpot credentials
     * @param string $contactId Contact ID
     * @param array $properties Properties to update
     * @return array Updated contact data
     */
    public function updateContact(array $credentials, string $contactId, array $properties): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'properties' => $properties
        ];

        return $this->makeApiRequest(
            $credentials,
            'PATCH',
            '/crm/v3/objects/contacts/' . $contactId,
            $payload
        );
    }

    // ========================================
    // COMPANIES API
    // ========================================

    /**
     * Search for companies
     *
     * @param array $credentials HubSpot credentials
     * @param string $query Search query
     * @param int $limit Maximum results
     * @return array Search results
     */
    public function searchCompanies(array $credentials, string $query, int $limit = 20): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'query' => $query,
            'limit' => $limit,
            'properties' => [
                'name',
                'domain',
                'industry',
                'phone',
                'city',
                'state',
                'country',
                'numberofemployees',
                'annualrevenue',
                'description',
                'createdate',
                'lastmodifieddate'
            ]
        ];

        return $this->makeApiRequest(
            $credentials,
            'POST',
            '/crm/v3/objects/companies/search',
            $payload
        );
    }

    /**
     * Get a company by ID
     *
     * @param array $credentials HubSpot credentials
     * @param string $companyId Company ID
     * @return array Company data
     */
    public function getCompany(array $credentials, string $companyId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            '/crm/v3/objects/companies/' . $companyId,
            null,
            [
                'properties' => 'name,domain,industry,phone,city,state,country,numberofemployees,annualrevenue,description,createdate,lastmodifieddate,hs_object_id'
            ]
        );
    }

    /**
     * Create a new company
     *
     * @param array $credentials HubSpot credentials
     * @param array $properties Company properties
     * @return array Created company data
     */
    public function createCompany(array $credentials, array $properties): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'properties' => $properties
        ];

        return $this->makeApiRequest(
            $credentials,
            'POST',
            '/crm/v3/objects/companies',
            $payload
        );
    }

    /**
     * Update an existing company
     *
     * @param array $credentials HubSpot credentials
     * @param string $companyId Company ID
     * @param array $properties Properties to update
     * @return array Updated company data
     */
    public function updateCompany(array $credentials, string $companyId, array $properties): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'properties' => $properties
        ];

        return $this->makeApiRequest(
            $credentials,
            'PATCH',
            '/crm/v3/objects/companies/' . $companyId,
            $payload
        );
    }

    // ========================================
    // DEALS API
    // ========================================

    /**
     * Search for deals
     *
     * @param array $credentials HubSpot credentials
     * @param string $query Search query
     * @param int $limit Maximum results
     * @return array Search results
     */
    public function searchDeals(array $credentials, string $query, int $limit = 20): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'query' => $query,
            'limit' => $limit,
            'properties' => [
                'dealname',
                'amount',
                'dealstage',
                'pipeline',
                'closedate',
                'hs_priority',
                'hubspot_owner_id',
                'createdate',
                'lastmodifieddate'
            ]
        ];

        return $this->makeApiRequest(
            $credentials,
            'POST',
            '/crm/v3/objects/deals/search',
            $payload
        );
    }

    /**
     * Get a deal by ID
     *
     * @param array $credentials HubSpot credentials
     * @param string $dealId Deal ID
     * @return array Deal data
     */
    public function getDeal(array $credentials, string $dealId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            '/crm/v3/objects/deals/' . $dealId,
            null,
            [
                'properties' => 'dealname,amount,dealstage,pipeline,closedate,hs_priority,hubspot_owner_id,createdate,lastmodifieddate,hs_object_id,description'
            ]
        );
    }

    /**
     * Create a new deal
     *
     * @param array $credentials HubSpot credentials
     * @param array $properties Deal properties
     * @return array Created deal data
     */
    public function createDeal(array $credentials, array $properties): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'properties' => $properties
        ];

        return $this->makeApiRequest(
            $credentials,
            'POST',
            '/crm/v3/objects/deals',
            $payload
        );
    }

    /**
     * Update an existing deal
     *
     * @param array $credentials HubSpot credentials
     * @param string $dealId Deal ID
     * @param array $properties Properties to update
     * @return array Updated deal data
     */
    public function updateDeal(array $credentials, string $dealId, array $properties): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'properties' => $properties
        ];

        return $this->makeApiRequest(
            $credentials,
            'PATCH',
            '/crm/v3/objects/deals/' . $dealId,
            $payload
        );
    }

    /**
     * Get all pipelines for deals
     *
     * @param array $credentials HubSpot credentials
     * @return array Pipelines with stages
     */
    public function getDealPipelines(array $credentials): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'GET',
            '/crm/v3/pipelines/deals'
        );
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Make an API request to HubSpot
     *
     * @param array $credentials HubSpot credentials
     * @param string $method HTTP method
     * @param string $endpoint API endpoint (relative to base URL)
     * @param array|null $payload Request payload for POST/PATCH
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
                self::API_BASE_URL . $endpoint,
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

            $message = $errorData['message'] ?? 'Unknown HubSpot error';
            $category = $errorData['category'] ?? '';
            $errors = $errorData['errors'] ?? [];

            // Build comprehensive error message
            $errorText = $message;
            if (!empty($errors)) {
                $errorDetails = array_map(fn($err) => $err['message'] ?? json_encode($err), $errors);
                $errorText .= ': ' . implode(', ', $errorDetails);
            }

            $suggestion = $this->getSuggestionForError($statusCode, $category, $errorText);

            throw new \RuntimeException(
                "HubSpot API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get a helpful suggestion based on the error
     *
     * @param int $statusCode HTTP status code
     * @param string $category HubSpot error category
     * @param string $errorText Error message
     * @return string Suggestion message
     */
    private function getSuggestionForError(int $statusCode, string $category, string $errorText): string
    {
        $suggestion = '';

        if ($statusCode === 401) {
            $suggestion = ' - Access token expired or invalid. Please reconnect to HubSpot.';
        } elseif ($statusCode === 403) {
            $suggestion = ' - Insufficient permissions. Check your HubSpot app scopes.';
        } elseif ($statusCode === 404) {
            $suggestion = ' - The requested resource was not found. Verify the ID is correct.';
        } elseif ($statusCode === 409) {
            $suggestion = ' - A conflict occurred. The record may already exist or be duplicated.';
        } elseif ($statusCode === 429) {
            $suggestion = ' - Rate limit exceeded. Please wait a moment and try again.';
        } elseif ($category === 'VALIDATION_ERROR' || stripos($errorText, 'validation') !== false) {
            $suggestion = ' - Check that all required properties are provided and values are valid.';
        } elseif ($category === 'OBJECT_NOT_FOUND') {
            $suggestion = ' - The specified object does not exist or has been deleted.';
        }

        return $suggestion;
    }
}
