<?php

namespace App\OAuth2;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;

/**
 * Wrike OAuth2 Provider
 *
 * Handles Wrike-specific OAuth2 flow with datacenter-aware API URL
 * Wrike returns a 'host' field in the token response that indicates the user's datacenter
 */
class WrikeProvider extends AbstractProvider
{
    use BearerAuthorizationTrait;

    /**
     * The host from the token response (datacenter-specific API URL)
     */
    protected ?string $host = null;

    /**
     * @return string
     */
    public function getBaseAuthorizationUrl(): string
    {
        return 'https://login.wrike.com/oauth2/authorize/v4';
    }

    /**
     * @param array<string, mixed> $params
     * @return string
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return 'https://login.wrike.com/oauth2/token';
    }

    /**
     * @param AccessToken $token
     * @return string
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        $host = $this->host ?? 'www.wrike.com';
        return "https://{$host}/api/v4/contacts?me=true";
    }

    /**
     * @return array<string>
     */
    protected function getDefaultScopes(): array
    {
        // Wrike uses space-separated scopes
        return ['wsReadWrite'];
    }

    /**
     * @return string
     */
    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * @param ResponseInterface $response
     * @param array<string, mixed>|string $data
     * @return void
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if ($response->getStatusCode() >= 400) {
            $error = isset($data['error']) ? $data['error'] : 'Unknown error';
            $errorDescription = isset($data['error_description']) ? $data['error_description'] : '';
            throw new IdentityProviderException(
                sprintf('%s: %s', $error, $errorDescription),
                $response->getStatusCode(),
                $data
            );
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param AccessToken $token
     * @return ResourceOwnerInterface
     */
    protected function createResourceOwner(array $response, AccessToken $token): ResourceOwnerInterface
    {
        return new WrikeResourceOwner($response);
    }

    /**
     * @param array<string, mixed> $response
     * @param \League\OAuth2\Client\Grant\AbstractGrant $grant
     * @return AccessTokenInterface
     */
    protected function createAccessToken(array $response, $grant): AccessTokenInterface
    {
        // Capture the host from the token response - critical for API URL
        if (isset($response['host'])) {
            $this->host = $response['host'];
        }

        return parent::createAccessToken($response, $grant);
    }

    /**
     * Get the captured host from the token response
     *
     * @return string|null
     */
    public function getHost(): ?string
    {
        return $this->host;
    }
}
