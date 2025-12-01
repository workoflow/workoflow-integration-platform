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

    /**
     * Extract the user's OID from Projektron
     *
     * @param array $credentials Projektron credentials (domain, jsessionid)
     * @return string User OID (e.g., "1611701511349_JUser")
     * @throws InvalidArgumentException If user OID cannot be found
     */
    public function getUserOid(array $credentials): string
    {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);
        $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

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

            // Extract user OID from the HTML (pattern: "1234567890_JUser")
            if (preg_match('/"(\d+_JUser)"/', $html, $matches)) {
                return $matches[1];
            }

            throw new InvalidArgumentException(
                'Could not find user OID in Projektron response. Your session may have expired.'
            );
        } catch (\Exception $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new InvalidArgumentException(
                'Failed to retrieve user OID: ' . $e->getMessage()
            );
        }
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

    /**
     * Get worklog (time entries) for a specific date
     *
     * @param array $credentials Projektron credentials (domain, jsessionid)
     * @param int $day Day of month (1-31)
     * @param int $month Month (1-12)
     * @param int $year Year (e.g., 2025)
     * @return array Worklog data with entries and week information
     * @throws InvalidArgumentException If request fails or data cannot be parsed
     */
    public function getWorklog(array $credentials, int $day, int $month, int $year): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);
        $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

        // Get user OID for the request
        $userOid = $this->getUserOid($credentials);

        // Build worklog URL with date parameters
        $worklogUrl = $this->buildWorklogUrl($url, $userOid, $day, $month, $year);

        try {
            $response = $this->httpClient->request('GET', $worklogUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);

            $html = $response->getContent();

            // Check if we got redirected to login
            if (strpos($html, 'multidaytimerecording') === false && strpos($html, 'login') !== false) {
                throw new InvalidArgumentException(
                    'Authentication failed. Your session may have expired. Please update your JSESSIONID cookie.'
                );
            }

            return $this->parseWorklogData($html, $url, $day, $month, $year);
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401 || $statusCode === 403) {
                throw new InvalidArgumentException(
                    'Authentication failed. Please verify your JSESSIONID is correct and not expired.'
                );
            }

            throw new InvalidArgumentException(
                'Failed to fetch Projektron worklog: HTTP ' . $statusCode . ' - ' . $e->getMessage()
            );
        } catch (\Exception $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new InvalidArgumentException(
                'Failed to fetch Projektron worklog: ' . $e->getMessage()
            );
        }
    }

    /**
     * Build the worklog URL with date parameters
     */
    private function buildWorklogUrl(string $baseUrl, string $userOid, int $day, int $month, int $year): string
    {
        $timestamp = (int) (microtime(true) * 1000);

        return sprintf(
            '%s/bcs/mybcs/multidaytimerecording/display?oid=%s&%s&%s&%s&pagetimestamp=%d',
            $baseUrl,
            urlencode($userOid),
            'multidaytimerecording,Selections,effortRecordingDate,day=' . $day,
            'multidaytimerecording,Selections,effortRecordingDate,month=' . $month,
            'multidaytimerecording,Selections,effortRecordingDate,year=' . $year,
            $timestamp
        );
    }

    /**
     * Parse worklog HTML to extract time entries
     *
     * @param string $html HTML content from Projektron worklog page
     * @param string $baseUrl Base URL for reference
     * @param int $day Requested day
     * @param int $month Requested month
     * @param int $year Requested year
     * @return array Parsed worklog data
     */
    private function parseWorklogData(string $html, string $baseUrl, int $day, int $month, int $year): array
    {
        try {
            // Extract BCS_UI_LOAD_EVENTS JSON from script tag
            if (!preg_match('/<script\s+id="BCS_UI_LOAD_EVENTS"[^>]*>(.+?)<\/script>/s', $html, $jsonMatch)) {
                throw new InvalidArgumentException('Could not find worklog data in response');
            }

            /** @var array<int, array{event: array<string, mixed>}> $events */
            $events = json_decode($jsonMatch[1], true);
            if (!is_array($events)) {
                throw new InvalidArgumentException('Invalid worklog data format');
            }

            // Extract task names from HTML using DOM
            $taskNames = $this->extractTaskNames($html);

            // Process events to extract time entries
            $entries = [];
            $weekDates = [];

            foreach ($events as $eventData) {
                if (!isset($eventData['event'])) {
                    continue;
                }
                $event = $eventData['event'];

                // Extract total hours per task from "attribute.duration.initValuesAndPatterns" events
                if (isset($event['$key']) && $event['$key'] === 'attribute.duration.initValuesAndPatterns') {
                    if (isset($event['attributeName']) && $event['attributeName'] === 'indicatorSumRealExpense') {
                        $taskOid = $event['entityOid'] ?? null;
                        $totalMinutes = $event['value'] ?? 0;

                        if ($taskOid && str_contains($taskOid, '_JTask')) {
                            if (!isset($entries[$taskOid])) {
                                $entries[$taskOid] = [
                                    'task_oid' => $taskOid,
                                    'task_name' => $taskNames[$taskOid] ?? '',
                                    'total_minutes' => 0,
                                    'total_hours' => 0.0,
                                    'daily_entries' => [],
                                ];
                            }
                            $entries[$taskOid]['total_minutes'] = (int) $totalMinutes;
                            $entries[$taskOid]['total_hours'] = round($totalMinutes / 60, 2);
                        }
                    }
                }

                // Extract daily entries from "attribute.initSetAndGet" events
                if (isset($event['$key']) && $event['$key'] === 'attribute.initSetAndGet') {
                    $attributeName = $event['attributeName'] ?? '';

                    // Pattern: listeditoid_1234567890_JTask.days_1736722800000
                    if (preg_match('/listeditoid_(\d+_JTask)\.days_(\d+)$/', $attributeName, $matches)) {
                        $taskOid = $matches[1];
                        $timestamp = (int) $matches[2];
                        $date = date('Y-m-d', $timestamp / 1000);

                        // Track week dates
                        if (!in_array($date, $weekDates)) {
                            $weekDates[] = $date;
                        }

                        // Initialize entry if not exists
                        if (!isset($entries[$taskOid])) {
                            $entries[$taskOid] = [
                                'task_oid' => $taskOid,
                                'task_name' => $taskNames[$taskOid] ?? '',
                                'total_minutes' => 0,
                                'total_hours' => 0.0,
                                'daily_entries' => [],
                            ];
                        }

                        // Initialize daily entry
                        /** @var array<string, array{hours: int, minutes: int}> $dailyEntries */
                        $dailyEntries = $entries[$taskOid]['daily_entries'];
                        if (!array_key_exists($date, $dailyEntries)) {
                            $entries[$taskOid]['daily_entries'][$date] = [
                                'hours' => 0,
                                'minutes' => 0,
                            ];
                        }
                    }
                }
            }

            // Sort week dates
            sort($weekDates);

            // Calculate total hours across all entries
            $totalHours = 0.0;
            foreach ($entries as $entry) {
                $totalHours += $entry['total_hours'];
            }

            return [
                'date' => [
                    'day' => $day,
                    'month' => $month,
                    'year' => $year,
                ],
                'week_dates' => $weekDates,
                'total_hours' => round($totalHours, 2),
                'entries' => array_values($entries),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse Projektron worklog HTML', [
                'error' => $e->getMessage()
            ]);

            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }

            throw new InvalidArgumentException(
                'Failed to parse Projektron worklog: ' . $e->getMessage()
            );
        }
    }

    /**
     * Extract task names from HTML using DOM
     *
     * @param string $html HTML content
     * @return array<string, string> Map of task OID to task name
     */
    private function extractTaskNames(string $html): array
    {
        $taskNames = [];

        try {
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

                if (!empty($name) && !isset($taskNames[$oid])) {
                    $taskNames[$oid] = $name;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not extract task names from HTML', [
                'error' => $e->getMessage()
            ]);
        }

        return $taskNames;
    }
}
