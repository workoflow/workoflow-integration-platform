<?php

namespace App\Service\Integration;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for SAP Cloud for Customer (C4C) OAuth2 token exchange.
 * Handles SAML2 Bearer assertion exchange for user delegation.
 */
class SapC4cOAuthService
{
    private const TOKEN_ENDPOINT = '/sap/bc/sec/oauth2/token';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Exchange SAML2 assertion for SAP C4C OAuth2 access token.
     *
     * Uses the OAuth 2.0 SAML 2.0 Bearer Assertion flow (RFC 7522).
     * This enables user delegation: API calls are attributed to the user
     * whose SAML assertion is provided.
     *
     * @param string $saml2Assertion    SAML2 assertion from Azure AD OBO flow
     * @param string $c4cBaseUrl        SAP C4C base URL (e.g., https://myXXXXXX.crm.ondemand.com)
     * @param string $c4cClientId       OAuth client ID registered in SAP C4C Admin
     * @param string $c4cClientSecret   OAuth client secret from SAP C4C Admin
     *
     * @return array Token response with access_token, token_type, expires_in
     *
     * @throws \RuntimeException When token exchange fails
     */
    public function exchangeSaml2ForC4cToken(
        string $saml2Assertion,
        string $c4cBaseUrl,
        string $c4cClientId,
        string $c4cClientSecret
    ): array {
        $tokenUrl = rtrim($c4cBaseUrl, '/') . self::TOKEN_ENDPOINT;

        $this->logger->info('SAP C4C OAuth: Starting SAML2 to C4C token exchange', [
            'c4c_base_url' => $c4cBaseUrl,
        ]);

        try {
            $response = $this->httpClient->request('POST', $tokenUrl, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("{$c4cClientId}:{$c4cClientSecret}"),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:saml2-bearer',
                    'client_id' => $c4cClientId,
                    'assertion' => base64_encode($saml2Assertion),
                    'scope' => 'UIWC:CC_HOME',
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            if (!isset($data['access_token'])) {
                throw new \RuntimeException('No access_token in SAP C4C OAuth response');
            }

            $this->logger->info('SAP C4C OAuth: Successfully obtained C4C access token', [
                'expires_in' => $data['expires_in'] ?? 'unknown',
            ]);

            // Add expiration timestamp for caching
            $data['expires_at'] = time() + ($data['expires_in'] ?? 3600);

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('SAP C4C OAuth: Token exchange failed', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'SAP C4C OAuth token exchange failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Build auth headers for SAP C4C API requests using OAuth2 Bearer token.
     *
     * @param string $accessToken SAP C4C OAuth2 access token
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
     * Validate OAuth credentials by attempting to fetch a token endpoint.
     *
     * @param string $c4cBaseUrl      SAP C4C base URL
     * @param string $c4cClientId     OAuth client ID
     * @param string $c4cClientSecret OAuth client secret
     *
     * @return array Validation result with success flag and message
     */
    public function validateOAuthCredentials(
        string $c4cBaseUrl,
        string $c4cClientId,
        string $c4cClientSecret
    ): array {
        $tokenUrl = rtrim($c4cBaseUrl, '/') . self::TOKEN_ENDPOINT;
        $statusCode = 0;
        $errorMessage = '';

        try {
            // Send a minimal request to validate credentials
            // This will fail with invalid_grant but should return 400, not 401
            // if the client credentials are correct
            $this->httpClient->request('POST', $tokenUrl, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("{$c4cClientId}:{$c4cClientSecret}"),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:saml2-bearer',
                    'client_id' => $c4cClientId,
                    'assertion' => base64_encode('test'),
                    'scope' => 'UIWC:CC_HOME',
                ],
                'timeout' => 10,
            ]);

            // If we get here without exception, something unexpected happened
            return [
                'success' => true,
                'message' => 'OAuth endpoint is accessible',
            ];
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorMessage = $e->getMessage();
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
        }

        // 400 = Bad Request (invalid assertion) - client credentials are valid
        // 401 = Unauthorized - client credentials are invalid
        if ($statusCode === 400) {
            return [
                'success' => true,
                'message' => 'OAuth client credentials are valid (token endpoint accessible)',
            ];
        }

        if ($statusCode === 401) {
            return [
                'success' => false,
                'message' => 'Invalid OAuth client credentials',
            ];
        }

        return [
            'success' => false,
            'message' => 'OAuth endpoint validation failed: ' . $errorMessage,
        ];
    }
}
