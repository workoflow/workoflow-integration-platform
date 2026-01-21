<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use InvalidArgumentException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;

class ConfluenceService
{
    /**
     * Maximum length for page content in AI-friendly responses
     */
    private const MAX_CONTENT_LENGTH = 3000;

    /**
     * Maximum length for comment content
     */
    private const MAX_COMMENT_LENGTH = 1000;

    public function __construct(
        private HttpClientInterface $httpClient
    ) {
    }

    /**
     * Convert Confluence storage format (HTML-like) to plain text.
     *
     * @param string $storage The storage format content
     * @param int $maxLength Maximum length of output (0 = no limit)
     * @return string Plain text content
     */
    private function convertStorageToPlainText(string $storage, int $maxLength = 3000): string
    {
        if (empty($storage)) {
            return '';
        }

        // Remove Confluence macros first (they contain lots of extra markup)
        $text = preg_replace('/<ac:structured-macro[^>]*>.*?<\/ac:structured-macro>/s', ' [macro] ', $storage) ?? $storage;

        // Remove CDATA sections (common in code blocks)
        $text = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $text) ?? $text;

        // Strip HTML tags but preserve some structure
        $text = preg_replace('/<(br|\/p|\/div|\/h[1-6]|\/li)[^>]*>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        // Truncate if needed
        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength) . '...';
        }

        return $text;
    }

    /**
     * Get the API base URL based on auth mode.
     *
     * For OAuth: https://api.atlassian.com/ex/confluence/{cloudId}
     * For API Token: user-provided URL (normalized)
     *
     * @param array $credentials Credentials including auth_mode, cloud_id, or url
     *
     * @return string API base URL
     */
    private function getApiBaseUrl(array $credentials): string
    {
        $authMode = $credentials['auth_mode'] ?? 'api_token';

        if ($authMode === 'oauth') {
            if (empty($credentials['cloud_id'])) {
                throw new InvalidArgumentException('OAuth mode requires cloud_id');
            }
            return 'https://api.atlassian.com/ex/confluence/' . $credentials['cloud_id'];
        }

        // API Token mode: validate and normalize user-provided URL
        return $this->validateAndNormalizeUrl($credentials['url']);
    }

    /**
     * Get authentication options for HTTP client based on auth mode.
     *
     * For OAuth: Bearer token authorization header
     * For API Token: Basic auth with username and api_token
     *
     * @param array $credentials Credentials including auth_mode and tokens
     *
     * @return array HTTP client options (either 'auth_basic' or 'headers')
     */
    private function getAuthOptions(array $credentials): array
    {
        $authMode = $credentials['auth_mode'] ?? 'api_token';

        if ($authMode === 'oauth') {
            if (empty($credentials['access_token'])) {
                throw new InvalidArgumentException('OAuth mode requires access_token');
            }
            return [
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials['access_token'],
                ],
            ];
        }

        // API Token mode: Basic auth
        return [
            'auth_basic' => [$credentials['username'], $credentials['api_token']],
        ];
    }

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
        $result = $this->testConnectionDetailed($credentials);
        return $result['success'];
    }

    /**
     * Test Confluence connection with detailed error reporting
     *
     * @param array $credentials Confluence credentials
     * @return array Detailed test result with success, message, details, and suggestions
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            // Get base URL based on auth mode (OAuth or API Token)
            $url = $this->getApiBaseUrl($credentials);
            $authOptions = $this->getAuthOptions($credentials);

            // Wiki prefix is always /wiki for both OAuth and API Token modes
            $authMode = $credentials['auth_mode'] ?? 'api_token';
            $wikiPrefix = '/wiki';

            error_log("Confluence test connection - Base URL: {$url}");
            error_log("Confluence test connection - Auth mode: " . $authMode);
            error_log("Confluence test connection - Has token: " . (!empty($credentials['access_token']) ? 'yes' : 'no'));
            error_log("Confluence test connection - Cloud ID: " . ($credentials['cloud_id'] ?? 'not_set'));

            // Test v1 API endpoint (used for search, get page, comments)
            try {
                $testUrl = $url . $wikiPrefix . '/rest/api/user/current';
                error_log("Confluence test connection - Testing URL: {$testUrl}");

                $response = $this->httpClient->request('GET', $testUrl, array_merge(
                    $authOptions,
                    ['timeout' => 10]
                ));

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/wiki/rest/api/user/current',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    // v1 API works, now test v2 API endpoint (used for creating/updating pages)
                    try {
                        $v2Url = $url . $wikiPrefix . '/api/v2/spaces';
                        error_log("Confluence test connection - Testing v2 URL: {$v2Url}");
                        $v2Response = $this->httpClient->request('GET', $v2Url, array_merge(
                            $authOptions,
                            [
                                'query' => ['limit' => 1],
                                'timeout' => 10,
                            ]
                        ));

                        $v2StatusCode = $v2Response->getStatusCode();
                        error_log("Confluence test connection - v2 API status: {$v2StatusCode}");

                        // Log error response body for debugging
                        if ($v2StatusCode !== 200) {
                            try {
                                $v2ErrorBody = $v2Response->getContent(false);
                                error_log("Confluence test connection - v2 API error response: {$v2ErrorBody}");
                            } catch (\Exception $logEx) {
                                error_log("Confluence test connection - Could not read v2 error body: " . $logEx->getMessage());
                            }
                        }

                        $testedEndpoints[] = [
                            'endpoint' => $wikiPrefix . '/api/v2/spaces',
                            'status' => $v2StatusCode === 200 ? 'success' : 'failed',
                            'http_code' => $v2StatusCode
                        ];

                        if ($v2StatusCode === 200) {
                            return [
                                'success' => true,
                                'message' => 'Connection successful',
                                'details' => 'Successfully connected to Confluence. Both v1 and v2 APIs are accessible.',
                                'suggestion' => '',
                                'tested_endpoints' => $testedEndpoints
                            ];
                        } else {
                            return [
                                'success' => false,
                                'message' => 'v2 API not accessible',
                                'details' => "v1 API works but v2 API returned HTTP {$v2StatusCode}. Page creation/update may not work.",
                                'suggestion' => 'Contact your Confluence administrator to verify API access permissions.',
                                'tested_endpoints' => $testedEndpoints
                            ];
                        }
                    /** @phpstan-ignore-next-line catch.neverThrown */
                    } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                        $v2StatusCode = $e->getResponse()->getStatusCode();
                        $testedEndpoints[] = [
                            'endpoint' => '/wiki/api/v2/spaces',
                            'status' => 'failed',
                            'http_code' => $v2StatusCode
                        ];

                        return [
                            'success' => false,
                            'message' => 'v2 API access denied',
                            'details' => "v1 API works but v2 API returned HTTP {$v2StatusCode}. Page creation/update will not work.",
                            'suggestion' => 'Ensure your API token has permissions to access Confluence v2 APIs.',
                            'tested_endpoints' => $testedEndpoints
                        ];
                    }
                } else {
                    // Non-200 response from v1 API (should not happen as HttpClient throws exception)
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: Unexpected response from Confluence API",
                        'suggestion' => 'Check the error details and verify your Confluence instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            /** @phpstan-ignore-next-line catch.neverThrown */
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();

                // Log detailed error response for debugging
                try {
                    $errorBody = $response->getContent(false);
                    error_log("Confluence test connection - Error response: {$errorBody}");
                } catch (\Exception $logEx) {
                    error_log("Confluence test connection - Could not read error body: " . $logEx->getMessage());
                }

                $testedEndpoints[] = [
                    'endpoint' => '/wiki/rest/api/user/current',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    $authMode = $credentials['auth_mode'] ?? 'api_token';
                    if ($authMode === 'oauth') {
                        return [
                            'success' => false,
                            'message' => 'OAuth authentication failed',
                            'details' => 'OAuth access token is invalid, expired, or missing required scopes.',
                            'suggestion' => 'Try reconnecting via "Connect with Atlassian". Make sure you grant all requested permissions.',
                            'tested_endpoints' => $testedEndpoints
                        ];
                    }
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Invalid email or API token. Please verify your credentials.',
                        'suggestion' => 'Check that your email and API token are correct. Create a new API token at https://id.atlassian.com/manage-profile/security/api-tokens',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'Your API token does not have sufficient permissions.',
                        'suggestion' => 'Ensure the API token has read and write access to Confluence spaces and pages.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 404) {
                    return [
                        'success' => false,
                        'message' => 'API endpoint not found',
                        'details' => 'The URL may not be a valid Confluence instance or the API is not available.',
                        'suggestion' => 'Verify the URL points to a Confluence Cloud instance (e.g., https://your-domain.atlassian.net)',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: " . $e->getMessage(),
                        'suggestion' => 'Check the error details and verify your Confluence instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach Confluence server',
                    'details' => 'Network error: ' . $e->getMessage(),
                    'suggestion' => 'Check the URL and your network connection. Verify the domain is correct and accessible.',
                    'tested_endpoints' => $testedEndpoints
                ];
            }
        } catch (InvalidArgumentException $e) {
            // URL validation error
            return [
                'success' => false,
                'message' => 'Invalid URL',
                'details' => $e->getMessage(),
                'suggestion' => 'Enter a valid Confluence URL like https://your-domain.atlassian.net (without /wiki)',
                'tested_endpoints' => $testedEndpoints
            ];
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

    public function search(array $credentials, string $query, int $limit = 25): array
    {
        $url = $this->getApiBaseUrl($credentials);
        $authOptions = $this->getAuthOptions($credentials);

        $response = $this->httpClient->request('GET', $url . '/wiki/rest/api/content/search', array_merge(
            $authOptions,
            [
                'query' => [
                    'cql' => $query,
                    'limit' => $limit,
                ],
            ]
        ));

        return $response->toArray();
    }

    public function getPage(array $credentials, string $pageId): array
    {
        $url = $this->getApiBaseUrl($credentials);
        $authOptions = $this->getAuthOptions($credentials);

        // Use v2 API which requires read:page:confluence scope (granular)
        // instead of v1 API which requires read:confluence-content.all (classic)
        $response = $this->httpClient->request('GET', $url . '/wiki/api/v2/pages/' . $pageId, array_merge(
            $authOptions,
            [
                'query' => [
                    'body-format' => 'storage',
                    'include-version' => 'true',
                ],
            ]
        ));

        $data = $response->toArray();

        // Convert to AI-friendly format with plain text content
        // v2 API returns body.storage.value structure
        $storageContent = $data['body']['storage']['value'] ?? '';

        // Get space info via separate call if needed for v2 API
        $spaceKey = '';
        $spaceName = '';
        if (!empty($data['spaceId'])) {
            try {
                $spaceResponse = $this->httpClient->request('GET', $url . '/wiki/api/v2/spaces/' . $data['spaceId'], $authOptions);
                $spaceData = $spaceResponse->toArray();
                $spaceKey = $spaceData['key'] ?? '';
                $spaceName = $spaceData['name'] ?? '';
            } catch (\Exception $e) {
                // Space info is optional, continue without it
                error_log("Failed to fetch space info for page {$pageId}: " . $e->getMessage());
            }
        }

        return [
            'id' => $data['id'] ?? '',
            'type' => 'page',
            'status' => $data['status'] ?? '',
            'title' => $data['title'] ?? '',
            'space' => [
                'key' => $spaceKey,
                'name' => $spaceName,
            ],
            'version' => [
                'number' => $data['version']['number'] ?? 1,
                'when' => $data['version']['createdAt'] ?? '',
                'by' => $data['version']['authorId'] ?? '',
            ],
            // Convert storage format to plain text for AI readability
            'content' => $this->convertStorageToPlainText($storageContent, self::MAX_CONTENT_LENGTH),
            'contentLength' => mb_strlen($storageContent),
            'isTruncated' => mb_strlen($storageContent) > self::MAX_CONTENT_LENGTH,
            '_links' => [
                'webui' => $data['_links']['webui'] ?? '',
            ],
        ];
    }

    public function getComments(array $credentials, string $pageId): array
    {
        $url = $this->getApiBaseUrl($credentials);
        $authOptions = $this->getAuthOptions($credentials);

        $response = $this->httpClient->request('GET', $url . '/wiki/rest/api/content/' . $pageId . '/child/comment', array_merge(
            $authOptions,
            [
                'query' => [
                    'expand' => 'body.storage,version',
                ],
            ]
        ));

        $data = $response->toArray();

        // Map comments to AI-friendly format with plain text content
        $comments = array_map(function ($comment) {
            $storageContent = $comment['body']['storage']['value'] ?? '';
            return [
                'id' => $comment['id'] ?? '',
                'title' => $comment['title'] ?? '',
                'author' => $comment['version']['by']['displayName'] ?? '',
                'created' => $comment['version']['when'] ?? '',
                // Convert storage format to plain text for AI readability
                'content' => $this->convertStorageToPlainText($storageContent, self::MAX_COMMENT_LENGTH),
            ];
        }, $data['results'] ?? []);

        return [
            'pageId' => $pageId,
            'total' => $data['size'] ?? count($comments),
            'comments' => $comments,
        ];
    }

    /**
     * Convert content from various formats to Confluence storage format
     *
     * @param string $content The content to convert
     * @param string $format The source format: 'markdown', 'plain', 'html', or 'storage'
     * @return string The content in Confluence storage format
     */
    private function convertToStorageFormat(string $content, string $format = 'markdown'): string
    {
        switch (strtolower($format)) {
            case 'storage':
                // Already in storage format, just return it
                return $content;

            case 'plain':
                // Convert plain text to storage format
                // Escape special characters and convert line breaks to paragraphs
                $escaped = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $paragraphs = explode("\n\n", $escaped);
                $html = '';
                foreach ($paragraphs as $para) {
                    $para = str_replace("\n", '<br />', $para);
                    if (trim($para)) {
                        $html .= '<p>' . $para . '</p>';
                    }
                }
                return $html ?: '<p>' . $escaped . '</p>';

            case 'html':
                // Basic HTML to storage format conversion
                // Confluence storage format is similar to HTML but with some differences
                $storage = $content;
                // Convert common HTML tags to Confluence equivalents
                $storage = str_replace(['<b>', '</b>'], ['<strong>', '</strong>'], $storage);
                $storage = str_replace(['<i>', '</i>'], ['<em>', '</em>'], $storage);
                return $storage;

            case 'markdown':
            default:
                // Convert markdown to Confluence storage format
                $storage = $content;

                // Headers (must be done before other replacements)
                $storage = preg_replace('/^##### (.+)$/m', '<h5>$1</h5>', $storage);
                $storage = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $storage);
                $storage = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $storage);
                $storage = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $storage);
                $storage = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $storage);

                // Bold and italic (order matters)
                $storage = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $storage);
                $storage = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $storage);
                $storage = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $storage);
                $storage = preg_replace('/___(.+?)___/s', '<strong><em>$1</em></strong>', $storage);
                $storage = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $storage);
                $storage = preg_replace('/_(.+?)_/s', '<em>$1</em>', $storage);

                // Code blocks
                $storage = preg_replace('/```(.*?)\n(.*?)```/s', '<ac:structured-macro ac:name="code"><ac:parameter ac:name="language">$1</ac:parameter><ac:plain-text-body><![CDATA[$2]]></ac:plain-text-body></ac:structured-macro>', $storage);
                $storage = preg_replace('/`([^`]+)`/', '<code>$1</code>', $storage);

                // Links
                $storage = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2">$1</a>', $storage);

                // Lists (basic support)
                $storage = preg_replace('/^\* (.+)$/m', '<ul><li>$1</li></ul>', $storage);
                $storage = preg_replace('/^- (.+)$/m', '<ul><li>$1</li></ul>', $storage);
                $storage = preg_replace('/^\d+\. (.+)$/m', '<ol><li>$1</li></ol>', $storage);

                // Merge adjacent list items
                $storage = preg_replace('/<\/ul>\n<ul>/', "\n", $storage);
                $storage = preg_replace('/<\/ol>\n<ol>/', "\n", $storage);

                // Horizontal rules
                $storage = preg_replace('/^---+$/m', '<hr />', $storage);

                // Paragraphs (wrap non-HTML content in paragraphs)
                $lines = explode("\n", $storage);
                $result = [];
                $inParagraph = false;
                $paragraphContent = '';

                foreach ($lines as $line) {
                    $trimmed = trim($line);

                    // Check if line starts with HTML tag
                    if (preg_match('/^<(h[1-6]|ul|ol|li|hr|ac:structured-macro|p|div|table|blockquote)/', $trimmed)) {
                        // Close any open paragraph
                        if ($inParagraph && $paragraphContent) {
                            $result[] = '<p>' . trim($paragraphContent) . '</p>';
                            $paragraphContent = '';
                            $inParagraph = false;
                        }
                        $result[] = $line;
                    } elseif ($trimmed === '') {
                        // Empty line - close paragraph if open
                        if ($inParagraph && $paragraphContent) {
                            $result[] = '<p>' . trim($paragraphContent) . '</p>';
                            $paragraphContent = '';
                            $inParagraph = false;
                        }
                    } else {
                        // Regular text - add to paragraph
                        if (!$inParagraph) {
                            $inParagraph = true;
                        } else {
                            $paragraphContent .= '<br />';
                        }
                        $paragraphContent .= $line;
                    }
                }

                // Close any remaining paragraph
                if ($inParagraph && $paragraphContent) {
                    $result[] = '<p>' . trim($paragraphContent) . '</p>';
                }

                return implode("\n", $result);
        }
    }

    /**
     * Update an existing page in Confluence
     *
     * @param array $credentials Confluence credentials
     * @param array $params Page update parameters
     * @return array Response with updated page details or error information
     */
    public function updatePage(array $credentials, array $params): array
    {
        try {
            $url = $this->getApiBaseUrl($credentials);
            $authOptions = $this->getAuthOptions($credentials);

            // First, get the current page to retrieve its version number
            $currentPageResponse = $this->httpClient->request('GET', $url . '/wiki/api/v2/pages/' . $params['pageId'], array_merge(
                $authOptions,
                [
                    'query' => [
                        'body-format' => 'storage',
                    ],
                ]
            ));

            $currentPage = $currentPageResponse->toArray();
            $currentVersion = $currentPage['version']['number'] ?? 1;

            // Prepare the update request body
            $body = [
                'id' => $params['pageId'],
                'status' => $params['status'] ?? $currentPage['status'] ?? 'current',
                'title' => $params['title'] ?? $currentPage['title'],
                'body' => [
                    'representation' => 'storage',
                    'value' => $this->convertToStorageFormat(
                        $params['content'],
                        $params['contentFormat'] ?? 'markdown'
                    )
                ],
                'version' => [
                    'number' => $currentVersion + 1,
                ]
            ];

            // Add version message if provided
            if (!empty($params['versionMessage'])) {
                $body['version']['message'] = $params['versionMessage'];
            }

            // Update the page using the v2 API
            $response = $this->httpClient->request('PUT', $url . '/wiki/api/v2/pages/' . $params['pageId'], array_merge(
                $authOptions,
                [
                    'headers' => array_merge(
                        $authOptions['headers'] ?? [],
                        [
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                        ]
                    ),
                    'json' => $body,
                ]
            ));

            $pageData = $response->toArray();

            // Construct the page URL
            $pageUrl = $url . '/wiki' . $pageData['_links']['webui'];

            return [
                'success' => true,
                'pageId' => $pageData['id'],
                'pageUrl' => $pageUrl,
                'title' => $pageData['title'],
                'spaceId' => $pageData['spaceId'],
                'status' => $pageData['status'],
                'message' => 'Page updated successfully',
                'version' => $pageData['version']['number'] ?? ($currentVersion + 1),
                'previousVersion' => $currentVersion,
            ];
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $errorData = $response->toArray(false);

            // Provide AI-friendly error messages
            $errorMessage = $errorData['message'] ?? 'Unknown error occurred';
            $details = '';

            if (str_contains($errorMessage, 'version') || str_contains($errorMessage, 'conflict')) {
                $details = 'Page was modified by another user. Try again to get the latest version or use force update if appropriate';
            } elseif (str_contains($errorMessage, 'does not have permission')) {
                $details = 'Check that the API token has write permissions for this page';
            } elseif (str_contains($errorMessage, 'not found')) {
                $details = 'Page not found. Use confluence_search to find the correct page ID';
            } elseif (str_contains($errorMessage, 'title')) {
                $details = 'Page title issue - it may be empty or too long. Check the title parameter';
            }

            return [
                'success' => false,
                'error' => $errorMessage,
                'details' => $details,
                'statusCode' => $response->getStatusCode(),
                'suggestion' => $details ?: 'Check the error message and adjust your parameters accordingly',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => 'An error occurred while updating the page',
                'suggestion' => 'Verify the page ID exists and try again',
            ];
        }
    }

    /**
     * Create a new page in Confluence
     *
     * @param array $credentials Confluence credentials
     * @param array $params Page creation parameters
     * @return array Response with page details or error information
     */
    public function createPage(array $credentials, array $params): array
    {
        try {
            $url = $this->getApiBaseUrl($credentials);
            $authOptions = $this->getAuthOptions($credentials);

            // Prepare the request body
            $body = [
                'status' => $params['status'] ?? 'current',
                'title' => $params['title'],
                'body' => [
                    'representation' => 'storage',
                    'value' => $this->convertToStorageFormat(
                        $params['content'],
                        $params['contentFormat'] ?? 'markdown'
                    )
                ]
            ];

            // Handle space identification (spaceId or spaceKey)
            if (!empty($params['spaceId'])) {
                $body['spaceId'] = $params['spaceId'];
            } elseif (!empty($params['spaceKey'])) {
                // Get space by key to find the ID using v2 API
                $spaceResponse = $this->httpClient->request('GET', $url . '/wiki/api/v2/spaces', array_merge(
                    $authOptions,
                    [
                        'query' => [
                            'keys' => $params['spaceKey'],
                        ],
                    ]
                ));

                $spaceData = $spaceResponse->toArray();
                if (!empty($spaceData['results'][0]['id'])) {
                    $body['spaceId'] = $spaceData['results'][0]['id'];
                } else {
                    throw new \RuntimeException("Space with key '{$params['spaceKey']}' not found");
                }
            } else {
                throw new \InvalidArgumentException('Either spaceId or spaceKey must be provided');
            }

            // Add parent page if specified
            if (!empty($params['parentId'])) {
                $body['parentId'] = $params['parentId'];
            }

            // Create the page using the v2 API
            $response = $this->httpClient->request('POST', $url . '/wiki/api/v2/pages', array_merge(
                $authOptions,
                [
                    'headers' => array_merge(
                        $authOptions['headers'] ?? [],
                        [
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                        ]
                    ),
                    'json' => $body,
                ]
            ));

            $pageData = $response->toArray();

            // Construct the page URL
            $pageUrl = $url . '/wiki' . $pageData['_links']['webui'];

            return [
                'success' => true,
                'pageId' => $pageData['id'],
                'pageUrl' => $pageUrl,
                'title' => $pageData['title'],
                'spaceId' => $pageData['spaceId'],
                'status' => $pageData['status'],
                'message' => 'Page created successfully',
                'version' => $pageData['version']['number'] ?? 1,
            ];
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $errorData = $response->toArray(false);

            // Provide AI-friendly error messages
            $errorMessage = $errorData['message'] ?? 'Unknown error occurred';
            $details = '';

            if (str_contains($errorMessage, 'does not have permission')) {
                $details = 'Check that the API token has write permissions for this space';
            } elseif (str_contains($errorMessage, 'title already exists')) {
                $details = 'A page with this title already exists in this space. Try a different title or update the existing page';
            } elseif (str_contains($errorMessage, 'space')) {
                $details = 'The specified space was not found. Use confluence_search with type=space to find available spaces';
            }

            return [
                'success' => false,
                'error' => $errorMessage,
                'details' => $details,
                'statusCode' => $response->getStatusCode(),
                'suggestion' => $details ?: 'Check the error message and adjust your parameters accordingly',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => 'An error occurred while creating the page',
                'suggestion' => 'Verify your parameters and try again',
            ];
        }
    }
}
