<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use InvalidArgumentException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;
use Psr\Log\LoggerInterface;

class ProjektronService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
    }

    private function validateAndNormalizeUrl(string $url): string
    {
        try {
            $uri = new Uri($url);
            $uri = UriNormalizer::normalize($uri, UriNormalizer::REMOVE_DEFAULT_PORT | UriNormalizer::REMOVE_DUPLICATE_SLASHES);

            $path = $uri->getPath();
            if ($path !== '/' && str_ends_with($path, '/')) {
                $uri = $uri->withPath(rtrim($path, '/'));
            }

            $scheme = $uri->getScheme();
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException("Projektron URL must use HTTP or HTTPS protocol. Got: '{$scheme}'");
            }

            $host = $uri->getHost();
            if (empty($host)) {
                throw new InvalidArgumentException("Projektron URL must include a valid domain name");
            }

            if ($scheme !== 'https') {
                throw new InvalidArgumentException(
                    "Projektron URL must use HTTPS for security. Got: '{$url}'. " .
                    "Please use 'https://' instead of '{$scheme}://'"
                );
            }

            return (string) $uri;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                "Invalid Projektron URL format: '{$url}'. " .
                "Please provide a valid URL like 'https://projektron.valantic.com'. " .
                "Error: " . $e->getMessage()
            );
        }
    }

    private function buildCookieHeader(string $jsessionid): string
    {
        return sprintf('JSESSIONID=%s', $jsessionid);
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $url = $this->validateAndNormalizeUrl($credentials['domain']);
            $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

            $taskListUrl = $url . '/bcs/mybcs/tasklist';

            $response = $this->httpClient->request('GET', $taskListUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 10,
                'max_redirects' => 5,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->warning('Projektron connection test failed', [
                    'status_code' => $statusCode,
                    'url' => $taskListUrl
                ]);
                return false;
            }

            $html = $response->getContent();

            // Check if we got a valid Projektron task list page (not a login page)
            if (strpos($html, 'tasklist') === false && strpos($html, 'login') !== false) {
                $this->logger->warning('Projektron returned login page - authentication failed');
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Projektron connection test exception', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get all bookable tasks from the user's personal Projektron task list
     *
     * @param array $credentials Projektron credentials (domain, jsessionid)
     * @return array Array of tasks with oid, name, and booking_url
     * @throws InvalidArgumentException If credentials are invalid or request fails
     */
    public function getAllTasks(array $credentials): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);
        $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

        // Fetch user's task list - follows redirect to get user-specific task list
        $taskListUrl = $url . '/bcs/mybcs/tasklist';

        try {
            $response = $this->httpClient->request('GET', $taskListUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);

            $html = $response->getContent();

            // Check if we got redirected to login
            if (strpos($html, 'tasklist') === false && strpos($html, 'login') !== false) {
                throw new InvalidArgumentException(
                    'Authentication failed. Your session may have expired. Please update your JSESSIONID cookie.'
                );
            }

            return $this->parseTaskList($html, $url);
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401 || $statusCode === 403) {
                throw new InvalidArgumentException(
                    'Authentication failed. Please verify your JSESSIONID is correct and not expired.'
                );
            }

            throw new InvalidArgumentException(
                'Failed to fetch Projektron tasks: HTTP ' . $statusCode . ' - ' . $e->getMessage()
            );
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                'Failed to fetch Projektron tasks: ' . $e->getMessage()
            );
        }
    }

    /**
     * Parse task list HTML to extract bookable tasks using PHP 8.4 DOM Selector
     *
     * Extracts task OIDs from data-tt attribute and task names from span.hover elements.
     *
     * @param string $html HTML content from Projektron task list
     * @param string $baseUrl Base URL for building booking URLs
     * @return array Array of tasks with oid, name, and booking_url
     */
    private function parseTaskList(string $html, string $baseUrl): array
    {
        $tasks = [];

        try {
            // Use PHP 8.4 DOM\HTMLDocument with CSS selectors
            $dom = \Dom\HTMLDocument::createFromString($html);

            // Find all task links with data-tt attribute containing _JTask
            $taskLinks = $dom->querySelectorAll('a[data-tt*="_JTask"]');

            foreach ($taskLinks as $link) {
                $oid = $link->getAttribute('data-tt');

                if (empty($oid)) {
                    continue;
                }

                // Extract task name from child span.hover element
                $nameSpan = $link->querySelector('span.hover');
                $name = $nameSpan ? trim($nameSpan->textContent) : '';

                // Build booking URL
                $bookingUrl = $baseUrl . '/bcs/taskdetail/effortrecording/edit?oid=' . $oid;

                // Use OID as key to ensure uniqueness
                $tasks[$oid] = [
                    'oid' => $oid,
                    'name' => $name,
                    'booking_url' => $bookingUrl,
                ];
            }

            // Return unique results (array_values to remove keys)
            return array_values($tasks);
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse Projektron task list HTML', [
                'error' => $e->getMessage()
            ]);

            throw new InvalidArgumentException(
                'Failed to parse Projektron task list: ' . $e->getMessage()
            );
        }
    }
}
