<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use InvalidArgumentException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;

class ConfluenceService
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

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
                throw new InvalidArgumentException("Confluence URL must use HTTP or HTTPS protocol. Got: '{$scheme}'");
            }

            // Validate host exists
            $host = $uri->getHost();
            if (empty($host)) {
                throw new InvalidArgumentException("Confluence URL must include a valid domain name");
            }

            // Require HTTPS for all hosted Atlassian products
            if ($scheme !== 'https') {
                throw new InvalidArgumentException(
                    "Confluence URL must use HTTPS for security. Got: '{$url}'. " .
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
                "Invalid Confluence URL format: '{$url}'. " .
                "Please provide a valid URL like 'https://your-domain.atlassian.net'. " .
                "Error: " . $e->getMessage()
            );
        }
    }

    public function testConnection(array $credentials): bool
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);
        $response = $this->httpClient->request('GET', $url . '/rest/api/user/current', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ]);

        return $response->getStatusCode() === 200;
    }

    public function search(array $credentials, string $query, int $limit = 25): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);
        $response = $this->httpClient->request('GET', $url . '/rest/api/content/search', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
            'query' => [
                'cql' => $query,
                'limit' => $limit,
            ],
        ]);

        return $response->toArray();
    }

    public function getPage(array $credentials, string $pageId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);
        $response = $this->httpClient->request('GET', $url . '/rest/api/content/' . $pageId, [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
            'query' => [
                'expand' => 'body.storage,version',
            ],
        ]);

        return $response->toArray();
    }

    public function getComments(array $credentials, string $pageId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);
        $response = $this->httpClient->request('GET', $url . '/rest/api/content/' . $pageId . '/child/comment', [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
            'query' => [
                'expand' => 'body.storage',
            ],
        ]);

        return $response->toArray();
    }
}