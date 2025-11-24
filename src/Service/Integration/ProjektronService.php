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

    private function buildCookieHeader(string $csrfToken, string $jsessionid): string
    {
        return sprintf('CSRF_Token=%s; JSESSIONID=%s', $csrfToken, $jsessionid);
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $url = $this->validateAndNormalizeUrl($credentials['domain']);
            $cookieHeader = $this->buildCookieHeader(
                $credentials['csrf_token'],
                $credentials['jsessionid']
            );

            $projectBrowserUrl = $url . '/bcs/projectbrowser/main/display?oid=3_JProjects&default,default,sourcechoice,tab=projecttree';

            $response = $this->httpClient->request('GET', $projectBrowserUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->warning('Projektron connection test failed', [
                    'status_code' => $statusCode,
                    'url' => $projectBrowserUrl
                ]);
                return false;
            }

            $html = $response->getContent();

            // Check if we got a valid Projektron page (not a login page)
            if (strpos($html, 'projectbrowser') === false && strpos($html, 'login') !== false) {
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
     * Get all tasks and projects from Projektron
     *
     * @param array $credentials Projektron credentials (domain, csrf_token, jsessionid)
     * @return array Array of tasks/projects with oid, type, and booking_url
     * @throws InvalidArgumentException If credentials are invalid or request fails
     */
    public function getAllTasks(array $credentials): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);
        $cookieHeader = $this->buildCookieHeader(
            $credentials['csrf_token'],
            $credentials['jsessionid']
        );

        $projectBrowserUrl = $url . '/bcs/projectbrowser/main/display?oid=3_JProjects&default,default,sourcechoice,tab=projecttree';

        try {
            $response = $this->httpClient->request('GET', $projectBrowserUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 30,
            ]);

            $html = $response->getContent();

            // Check if we got redirected to login
            if (strpos($html, 'projectbrowser') === false && strpos($html, 'login') !== false) {
                throw new InvalidArgumentException(
                    'Authentication failed. Your session may have expired. Please update your CSRF_Token and JSESSIONID cookies.'
                );
            }

            return $this->parseProjectsAndTasks($html, $url);
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401 || $statusCode === 403) {
                throw new InvalidArgumentException(
                    'Authentication failed. Please verify your CSRF_Token and JSESSIONID are correct and not expired.'
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
     * Parse HTML to extract project and task OIDs using PHP 8.4 DOM Selector
     *
     * @param string $html HTML content from Projektron
     * @param string $baseUrl Base URL for building booking URLs
     * @return array Array of tasks with oid, type, and booking_url
     */
    private function parseProjectsAndTasks(string $html, string $baseUrl): array
    {
        $tasks = [];

        try {
            // Use PHP 8.4 DOM\HTMLDocument with CSS selectors
            $dom = \Dom\HTMLDocument::createFromString($html);

            // Find all links containing oid parameter
            $links = $dom->querySelectorAll('a[href*="oid="]');

            foreach ($links as $link) {
                $href = $link->getAttribute('href');

                // Extract OID using regex (more reliable than parsing query string)
                if (preg_match('/oid=([0-9]+_J(?:Task|Project))/i', $href, $matches)) {
                    $oid = $matches[1];

                    // Determine type
                    $type = stripos($oid, '_JTask') !== false ? 'task' : 'project';

                    // Build booking URL
                    $bookingUrl = $baseUrl . '/bcs/taskdetail/effortrecording/edit?oid=' . $oid;

                    $tasks[$oid] = [
                        'oid' => $oid,
                        'type' => $type,
                        'booking_url' => $bookingUrl
                    ];
                }
            }

            // Return unique results (array_values to remove keys)
            return array_values($tasks);
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse Projektron HTML', [
                'error' => $e->getMessage()
            ]);

            throw new InvalidArgumentException(
                'Failed to parse Projektron project browser: ' . $e->getMessage()
            );
        }
    }
}
