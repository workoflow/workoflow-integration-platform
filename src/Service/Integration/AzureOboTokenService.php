<?php

namespace App\Service\Integration;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for Azure AD On-Behalf-Of (OBO) token exchange.
 * Exchanges user JWT tokens for SAML2 assertions for SAP C4C user delegation.
 */
class AzureOboTokenService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Exchange Azure AD JWT for SAML2 assertion using On-Behalf-Of flow.
     *
     * This enables user delegation: actions in SAP C4C will be attributed to
     * the actual user rather than a technical service account.
     *
     * @param string $userJwtToken    User's Azure AD JWT token
     * @param string $azureTenantId   Azure AD tenant ID (GUID)
     * @param string $azureClientId   Workoflow app's Azure AD client ID
     * @param string $azureClientSecret Workoflow app's Azure AD client secret
     * @param string $c4cTenantUrl    SAP C4C tenant URL (e.g., myXXXXXX.crm.ondemand.com)
     *
     * @return string SAML2 assertion token
     *
     * @throws \RuntimeException When token exchange fails
     */
    public function exchangeJwtForSaml2(
        string $userJwtToken,
        string $azureTenantId,
        string $azureClientId,
        string $azureClientSecret,
        string $c4cTenantUrl
    ): string {
        $tokenEndpoint = "https://login.microsoftonline.com/{$azureTenantId}/oauth2/v2.0/token";

        $this->logger->info('Azure OBO: Starting JWT to SAML2 exchange', [
            'tenant_id' => $azureTenantId,
            'c4c_tenant' => $c4cTenantUrl,
        ]);

        try {
            $response = $this->httpClient->request('POST', $tokenEndpoint, [
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'client_id' => $azureClientId,
                    'client_secret' => $azureClientSecret,
                    'assertion' => $userJwtToken,
                    'requested_token_use' => 'on_behalf_of',
                    'scope' => "https://{$c4cTenantUrl}/.default",
                    'requested_token_type' => 'urn:ietf:params:oauth:token-type:saml2',
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            if (!isset($data['access_token'])) {
                throw new \RuntimeException('No access_token in Azure OBO response');
            }

            $this->logger->info('Azure OBO: Successfully exchanged JWT for SAML2 assertion');

            return $data['access_token'];
        } catch (\Exception $e) {
            $this->logger->error('Azure OBO: Token exchange failed', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Azure AD OBO token exchange failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Exchange Azure AD JWT for a standard OAuth2 access token using On-Behalf-Of flow.
     *
     * Alternative to SAML2 exchange when the target resource supports OAuth2 directly.
     *
     * @param string $userJwtToken    User's Azure AD JWT token
     * @param string $azureTenantId   Azure AD tenant ID (GUID)
     * @param string $azureClientId   Workoflow app's Azure AD client ID
     * @param string $azureClientSecret Workoflow app's Azure AD client secret
     * @param string $scope           Target resource scope
     *
     * @return array Token response with access_token, token_type, expires_in
     *
     * @throws \RuntimeException When token exchange fails
     */
    public function exchangeJwtForAccessToken(
        string $userJwtToken,
        string $azureTenantId,
        string $azureClientId,
        string $azureClientSecret,
        string $scope
    ): array {
        $tokenEndpoint = "https://login.microsoftonline.com/{$azureTenantId}/oauth2/v2.0/token";

        $this->logger->info('Azure OBO: Starting JWT to access token exchange', [
            'tenant_id' => $azureTenantId,
            'scope' => $scope,
        ]);

        try {
            $response = $this->httpClient->request('POST', $tokenEndpoint, [
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'client_id' => $azureClientId,
                    'client_secret' => $azureClientSecret,
                    'assertion' => $userJwtToken,
                    'requested_token_use' => 'on_behalf_of',
                    'scope' => $scope,
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            if (!isset($data['access_token'])) {
                throw new \RuntimeException('No access_token in Azure OBO response');
            }

            $this->logger->info('Azure OBO: Successfully exchanged JWT for access token');

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Azure OBO: Token exchange failed', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Azure AD OBO token exchange failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
