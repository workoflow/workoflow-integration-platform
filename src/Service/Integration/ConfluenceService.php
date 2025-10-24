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
    ) {
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
            // Validate and normalize URL
            $url = $this->validateAndNormalizeUrl($credentials['url']);

            // Test v1 API endpoint (used for search, get page, comments)
            try {
                $response = $this->httpClient->request('GET', $url . '/rest/api/user/current', [
                    'auth_basic' => [$credentials['username'], $credentials['api_token']],
                    'timeout' => 10,
                ]);

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/rest/api/user/current',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    // v1 API works, now test v2 API endpoint (used for creating/updating pages)
                    try {
                        $v2Response = $this->httpClient->request('GET', $url . '/wiki/api/v2/spaces', [
                            'auth_basic' => [$credentials['username'], $credentials['api_token']],
                            'query' => ['limit' => 1],
                            'timeout' => 10,
                        ]);

                        $v2StatusCode = $v2Response->getStatusCode();
                        $testedEndpoints[] = [
                            'endpoint' => '/wiki/api/v2/spaces',
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
                $testedEndpoints[] = [
                    'endpoint' => '/rest/api/user/current',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
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
            $url = $this->validateAndNormalizeUrl($credentials['url']);

            // First, get the current page to retrieve its version number
            $currentPageResponse = $this->httpClient->request('GET', $url . '/wiki/api/v2/pages/' . $params['pageId'], [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => [
                    'body-format' => 'storage',
                ],
            ]);

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
            $response = $this->httpClient->request('PUT', $url . '/wiki/api/v2/pages/' . $params['pageId'], [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'json' => $body,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);

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
            $url = $this->validateAndNormalizeUrl($credentials['url']);

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
                $spaceResponse = $this->httpClient->request('GET', $url . '/wiki/api/v2/spaces', [
                    'auth_basic' => [$credentials['username'], $credentials['api_token']],
                    'query' => [
                        'keys' => $params['spaceKey'],
                    ],
                ]);

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
            $response = $this->httpClient->request('POST', $url . '/wiki/api/v2/pages', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'json' => $body,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);

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
