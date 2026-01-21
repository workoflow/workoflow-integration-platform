<?php

namespace App\Service\Integration;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for Atlassian OAuth 2.0 (3LO) authentication.
 * Used by both Jira and Confluence integrations.
 *
 * @see https://developer.atlassian.com/cloud/jira/platform/oauth-2-3lo-apps/
 */
class AtlassianOAuthService
{
    private const AUTH_URL = 'https://auth.atlassian.com/authorize';
    private const TOKEN_URL = 'https://auth.atlassian.com/oauth/token';
    private const RESOURCES_URL = 'https://api.atlassian.com/oauth/token/accessible-resources';

    // Token buffer: refresh 5 minutes before expiry
    private const TOKEN_EXPIRY_BUFFER = 300;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?string $clientId = null,
        private ?string $clientSecret = null,
        private ?string $redirectUri = null,
    ) {
    }

    /**
     * Check if Atlassian OAuth is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret) && !empty($this->redirectUri);
    }

    /**
     * Ensure OAuth is properly configured before use.
     *
     * @throws \RuntimeException When OAuth is not configured
     */
    private function ensureConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'Atlassian OAuth is not configured. Please set ATLASSIAN_CLIENT_ID and ATLASSIAN_CLIENT_SECRET environment variables.'
            );
        }
    }

    /**
     * Generate the authorization URL for Atlassian OAuth 2.0 (3LO).
     *
     * @param array  $scopes Array of OAuth scopes
     * @param string $state  State parameter for CSRF protection
     *
     * @return string Authorization URL
     */
    public function getAuthorizationUrl(array $scopes, string $state): string
    {
        $this->ensureConfigured();

        // Always include offline_access for refresh tokens
        if (!in_array('offline_access', $scopes, true)) {
            $scopes[] = 'offline_access';
        }

        $params = [
            'audience' => 'api.atlassian.com',
            'client_id' => $this->clientId,
            'scope' => implode(' ', $scopes),
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'prompt' => 'consent',
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access and refresh tokens.
     *
     * @param string $code Authorization code from callback
     *
     * @return array Token response with access_token, refresh_token, expires_in, scope
     *
     * @throws \RuntimeException When token exchange fails
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $this->ensureConfigured();
        $this->logger->info('Atlassian OAuth: Exchanging authorization code for tokens');

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri,
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            if (!isset($data['access_token'])) {
                throw new \RuntimeException('No access_token in Atlassian OAuth response');
            }

            $this->logger->info('Atlassian OAuth: Successfully obtained tokens', [
                'expires_in' => $data['expires_in'] ?? 'unknown',
                'scope' => $data['scope'] ?? 'unknown',
            ]);

            // Add expiration timestamp for caching
            $data['expires_at'] = time() + ($data['expires_in'] ?? 3600);

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Atlassian OAuth: Token exchange failed', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Atlassian OAuth token exchange failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @param string $refreshToken Refresh token
     *
     * @return array Token response with new access_token, refresh_token, expires_in
     *
     * @throws \RuntimeException When refresh fails
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $this->ensureConfigured();
        $this->logger->info('Atlassian OAuth: Refreshing access token');

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refreshToken,
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            if (!isset($data['access_token'])) {
                throw new \RuntimeException('No access_token in Atlassian OAuth refresh response');
            }

            $this->logger->info('Atlassian OAuth: Successfully refreshed access token', [
                'expires_in' => $data['expires_in'] ?? 'unknown',
            ]);

            // Add expiration timestamp
            $data['expires_at'] = time() + ($data['expires_in'] ?? 3600);

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Atlassian OAuth: Token refresh failed', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Atlassian OAuth token refresh failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get accessible Atlassian resources (sites) for the access token.
     *
     * @param string $accessToken Access token
     *
     * @return array Array of accessible resources with id (cloudId), url, name, scopes
     *
     * @throws \RuntimeException When request fails
     */
    public function getAccessibleResources(string $accessToken): array
    {
        $this->logger->info('Atlassian OAuth: Fetching accessible resources');

        try {
            $response = $this->httpClient->request('GET', self::RESOURCES_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            $resources = $response->toArray();

            $this->logger->info('Atlassian OAuth: Found accessible resources', [
                'count' => count($resources),
            ]);

            return $resources;
        } catch (\Exception $e) {
            $this->logger->error('Atlassian OAuth: Failed to get accessible resources', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Failed to get accessible Atlassian resources: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Ensure credentials have a valid access token, refreshing if needed.
     *
     * @param array $credentials Credentials with access_token, refresh_token, expires_at
     *
     * @return array Credentials with valid access_token (may include new refresh_token)
     *
     * @throws \RuntimeException When token cannot be refreshed
     */
    public function ensureValidToken(array $credentials): array
    {
        // Check if token exists and is still valid
        $expiresAt = $credentials['expires_at'] ?? 0;
        $isExpired = $expiresAt <= (time() + self::TOKEN_EXPIRY_BUFFER);

        if (!$isExpired && !empty($credentials['access_token'])) {
            return $credentials;
        }

        // Token expired or missing, need to refresh
        if (empty($credentials['refresh_token'])) {
            throw new \RuntimeException(
                'Atlassian OAuth: Access token expired and no refresh token available. ' .
                'Please reconnect via "Connect with Atlassian".'
            );
        }

        $this->logger->info('Atlassian OAuth: Access token expired, refreshing...');

        $newTokens = $this->refreshAccessToken($credentials['refresh_token']);

        // Merge new tokens with existing credentials
        return array_merge($credentials, [
            'access_token' => $newTokens['access_token'],
            'refresh_token' => $newTokens['refresh_token'] ?? $credentials['refresh_token'],
            'expires_at' => $newTokens['expires_at'],
        ]);
    }

    /**
     * Build Bearer auth headers for Atlassian API requests.
     *
     * @param string $accessToken Access token
     *
     * @return array HTTP headers for API requests
     */
    public function getBearerAuthHeaders(string $accessToken): array
    {
        return [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Get the Jira API base URL for OAuth requests.
     *
     * @param string $cloudId Cloud ID from accessible resources
     *
     * @return string API base URL
     */
    public function getJiraApiBaseUrl(string $cloudId): string
    {
        return "https://api.atlassian.com/ex/jira/{$cloudId}";
    }

    /**
     * Get the Confluence API base URL for OAuth requests.
     *
     * @param string $cloudId Cloud ID from accessible resources
     *
     * @return string API base URL
     */
    public function getConfluenceApiBaseUrl(string $cloudId): string
    {
        return "https://api.atlassian.com/ex/confluence/{$cloudId}";
    }

    /**
     * Get default scopes for Jira integration.
     *
     * @return array Jira OAuth scopes
     */
    public static function getJiraScopes(): array
    {
        return [
            'read:me',
            'read:account',
            'read:jira-work',
            'write:jira-work',
            'read:jira-user',
            'offline_access',
        ];
    }

    /**
     * Get default scopes for Confluence integration.
     *
     * @return array Confluence OAuth scopes
     */
    public static function getConfluenceScopes(): array
    {
        return [
            'read:me',
            'read:account',
            'read:confluence-content.all',
            'write:confluence-content',
            'read:confluence-space.summary',
            'offline_access',
        ];
    }
}
