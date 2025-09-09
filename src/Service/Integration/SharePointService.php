<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Response;

class SharePointService
{
    private const GRAPH_API_BASE = 'https://graph.microsoft.com/v1.0';
    
    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

    public function testConnection(array $credentials): bool
    {
        try {
            error_log('Testing SharePoint connection with token: ' . substr($credentials['access_token'], 0, 20) . '...');
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me', [
                'auth_bearer' => $credentials['access_token'],
            ]);
            error_log('Test connection successful, status: ' . $response->getStatusCode());
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            error_log('Test connection failed: ' . $e->getMessage());
            return false;
        }
    }

    public function searchDocuments(array $credentials, string $query, int $limit = 25): array
    {
        // The /search/query endpoint requires Application permissions
        // With delegated permissions, we need to use site-specific search
        // First, get the user's sites, then search within them
        
        try {
            $results = [];
            $remainingLimit = $limit;
            
            // 1. Search for matching sites
            try {
                $sitesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites', [
                    'auth_bearer' => $credentials['access_token'],
                    'query' => [
                        'search' => $query,
                        '$top' => min($remainingLimit, 10)
                    ]
                ]);
                
                $sites = $sitesResponse->toArray();
                
                if (isset($sites['value'])) {
                    foreach ($sites['value'] as $site) {
                        $results[] = [
                            'type' => 'site',
                            'id' => $site['id'] ?? '',
                            'name' => $site['displayName'] ?? $site['name'] ?? '',
                            'webUrl' => $site['webUrl'] ?? '',
                            'description' => $site['description'] ?? ''
                        ];
                        $remainingLimit--;
                        if ($remainingLimit <= 0) break;
                    }
                    
                    // For each site found, also search for pages and documents within it
                    foreach ($sites['value'] as $site) {
                        if ($remainingLimit <= 0) break;
                        
                        // Search for pages in this site
                        try {
                            $pagesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites/' . $site['id'] . '/pages', [
                                'auth_bearer' => $credentials['access_token'],
                                'query' => [
                                    '$top' => min($remainingLimit, 10),
                                    '$filter' => "contains(title,'" . str_replace("'", "''", $query) . "') or contains(name,'" . str_replace("'", "''", $query) . "')"
                                ]
                            ]);
                            
                            $pages = $pagesResponse->toArray();
                            if (isset($pages['value'])) {
                                foreach ($pages['value'] as $page) {
                                    $results[] = [
                                        'type' => 'page',
                                        'id' => $page['id'] ?? '',
                                        'name' => $page['title'] ?? $page['name'] ?? '',
                                        'webUrl' => $page['webUrl'] ?? '',
                                        'description' => $page['description'] ?? '',
                                        'siteId' => $site['id'],
                                        'siteName' => $site['displayName'] ?? $site['name'] ?? ''
                                    ];
                                    $remainingLimit--;
                                    if ($remainingLimit <= 0) break;
                                }
                            }
                        } catch (\Exception $e) {
                            error_log('Pages search in site ' . $site['id'] . ' failed: ' . $e->getMessage());
                        }
                        
                        // Search for documents in this site's drive
                        try {
                            $driveSearchResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites/' . $site['id'] . '/drive/search(q=\'' . urlencode($query) . '\')', [
                                'auth_bearer' => $credentials['access_token'],
                                'query' => [
                                    '$top' => min($remainingLimit, 10)
                                ]
                            ]);
                            
                            $driveResults = $driveSearchResponse->toArray();
                            if (isset($driveResults['value'])) {
                                foreach ($driveResults['value'] as $item) {
                                    $results[] = [
                                        'type' => 'document',
                                        'id' => $item['id'] ?? '',
                                        'name' => $item['name'] ?? '',
                                        'webUrl' => $item['webUrl'] ?? '',
                                        'size' => $item['size'] ?? 0,
                                        'lastModified' => $item['lastModifiedDateTime'] ?? '',
                                        'siteId' => $site['id'],
                                        'siteName' => $site['displayName'] ?? $site['name'] ?? ''
                                    ];
                                    $remainingLimit--;
                                    if ($remainingLimit <= 0) break;
                                }
                            }
                        } catch (\Exception $e) {
                            error_log('Drive search in site ' . $site['id'] . ' failed: ' . $e->getMessage());
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log('Sites search failed: ' . $e->getMessage());
            }
            
            // 2. Also search in user's OneDrive if we still have room
            if ($remainingLimit > 0) {
                try {
                    $driveSearchResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/drive/search(q=\'' . urlencode($query) . '\')', [
                        'auth_bearer' => $credentials['access_token'],
                        'query' => [
                            '$top' => $remainingLimit
                        ]
                    ]);
                    
                    $driveResults = $driveSearchResponse->toArray();
                    if (isset($driveResults['value'])) {
                        foreach ($driveResults['value'] as $item) {
                            $results[] = [
                                'type' => 'driveItem',
                                'id' => $item['id'] ?? '',
                                'name' => $item['name'] ?? '',
                                'webUrl' => $item['webUrl'] ?? '',
                                'size' => $item['size'] ?? 0,
                                'lastModified' => $item['lastModifiedDateTime'] ?? ''
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    error_log('Personal drive search failed: ' . $e->getMessage());
                }
            }
            
            return [
                'value' => $results,
                'count' => count($results)
            ];
            
        } catch (\Exception $e) {
            error_log('SharePoint Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function searchPages(array $credentials, string $query, int $limit = 25): array
    {
        try {
            $results = [];
            $remainingLimit = $limit;
            
            // First get all accessible sites
            $sitesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$top' => 50  // Get more sites to search within
                ]
            ]);
            
            $sites = $sitesResponse->toArray();
            
            if (isset($sites['value'])) {
                foreach ($sites['value'] as $site) {
                    if ($remainingLimit <= 0) break;
                    
                    try {
                        // Search for pages in each site
                        $pagesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites/' . $site['id'] . '/pages', [
                            'auth_bearer' => $credentials['access_token'],
                            'query' => [
                                '$top' => min($remainingLimit, 25),
                                '$filter' => "contains(tolower(title),'" . strtolower(str_replace("'", "''", $query)) . "') or contains(tolower(name),'" . strtolower(str_replace("'", "''", $query)) . "')"
                            ]
                        ]);
                        
                        $pages = $pagesResponse->toArray();
                        if (isset($pages['value'])) {
                            foreach ($pages['value'] as $page) {
                                $results[] = [
                                    'type' => 'page',
                                    'id' => $page['id'] ?? '',
                                    'title' => $page['title'] ?? $page['name'] ?? '',
                                    'name' => $page['name'] ?? '',
                                    'webUrl' => $page['webUrl'] ?? '',
                                    'description' => $page['description'] ?? '',
                                    'createdDateTime' => $page['createdDateTime'] ?? '',
                                    'lastModifiedDateTime' => $page['lastModifiedDateTime'] ?? '',
                                    'siteId' => $site['id'],
                                    'siteName' => $site['displayName'] ?? $site['name'] ?? '',
                                    'siteUrl' => $site['webUrl'] ?? ''
                                ];
                                $remainingLimit--;
                                if ($remainingLimit <= 0) break;
                            }
                        }
                    } catch (\Exception $e) {
                        error_log('Pages search in site ' . $site['id'] . ' failed: ' . $e->getMessage());
                    }
                }
            }
            
            return [
                'value' => $results,
                'count' => count($results),
                'searchQuery' => $query
            ];
            
        } catch (\Exception $e) {
            error_log('SharePoint Pages Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function readPage(array $credentials, string $siteId, string $pageId): array
    {
        try {
            error_log('Reading SharePoint page - SiteID: ' . $siteId . ', PageID: ' . $pageId);
            
            // Check if siteId looks like a name rather than a proper ID
            // A proper site ID contains commas and GUIDs, or is in hostname:path format
            if (!str_contains($siteId, ',') && !str_contains($siteId, '.sharepoint.com')) {
                error_log('SiteID appears to be a name, attempting to resolve to actual site ID');
                
                // Search for the site by name
                $sitesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites', [
                    'auth_bearer' => $credentials['access_token'],
                    'query' => [
                        'search' => $siteId,
                        '$top' => 10
                    ]
                ]);
                
                $sites = $sitesResponse->toArray();
                
                if (isset($sites['value']) && count($sites['value']) > 0) {
                    // Try to find exact match first
                    $resolvedSiteId = null;
                    foreach ($sites['value'] as $site) {
                        if (isset($site['displayName']) && strcasecmp($site['displayName'], $siteId) === 0) {
                            $resolvedSiteId = $site['id'];
                            error_log('Found exact site match: ' . $site['displayName'] . ' -> ' . $resolvedSiteId);
                            break;
                        }
                        if (isset($site['name']) && strcasecmp($site['name'], $siteId) === 0) {
                            $resolvedSiteId = $site['id'];
                            error_log('Found exact site match: ' . $site['name'] . ' -> ' . $resolvedSiteId);
                            break;
                        }
                    }
                    
                    // If no exact match, use the first result
                    if (!$resolvedSiteId && isset($sites['value'][0]['id'])) {
                        $resolvedSiteId = $sites['value'][0]['id'];
                        $siteName = $sites['value'][0]['displayName'] ?? $sites['value'][0]['name'] ?? 'Unknown';
                        error_log('Using first site match: ' . $siteName . ' -> ' . $resolvedSiteId);
                    }
                    
                    if ($resolvedSiteId) {
                        $siteId = $resolvedSiteId;
                    } else {
                        throw new \Exception('Could not resolve site name "' . $siteId . '" to a valid site ID');
                    }
                } else {
                    throw new \Exception('No sites found matching "' . $siteId . '"');
                }
            }
            
            // Expand canvasLayout to get page content
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/pages/{$pageId}/microsoft.graph.sitePage", [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$expand' => 'canvasLayout'
                ]
            ]);

            $data = $response->toArray();
            
            // Extract content from canvasLayout if available
            $content = $this->extractPageContent($data);
            if ($content !== null) {
                $data['content'] = $content;
                $data['contentLength'] = strlen($content);
                error_log('SharePoint page read successfully with content (length: ' . strlen($content) . ')');
            } else {
                error_log('SharePoint page read successfully (no extractable content)');
            }
            
            return $data;
            
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint Read Page Client Error (' . $statusCode . '): ' . $errorContent);
            error_log('Request URL: ' . self::GRAPH_API_BASE . "/sites/{$siteId}/pages/{$pageId}");
            
            // Parse error details if available
            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to read SharePoint page (HTTP ' . $statusCode . '): ' . $errorContent);
            
        } catch (\Exception $e) {
            error_log('SharePoint Read Page Error: ' . $e->getMessage());
            throw new \Exception('Failed to read SharePoint page: ' . $e->getMessage());
        }
    }

    public function listFiles(array $credentials, string $siteId, string $path = ''): array
    {
        try {
            $endpoint = $path 
                ? "/sites/{$siteId}/drive/root:/{$path}:/children"
                : "/sites/{$siteId}/drive/root/children";
            
            error_log('Listing SharePoint files - SiteID: ' . $siteId . ', Path: ' . ($path ?: 'root'));
            
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . $endpoint, [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$select' => 'id,name,size,lastModifiedDateTime,file,folder,webUrl',
                    '$top' => 100
                ]
            ]);

            $data = $response->toArray();
            error_log('SharePoint files listed successfully, count: ' . (isset($data['value']) ? count($data['value']) : 0));
            return $data;
            
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint List Files Client Error (' . $statusCode . '): ' . $errorContent);
            
            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to list SharePoint files (HTTP ' . $statusCode . ')');
            
        } catch (\Exception $e) {
            error_log('SharePoint List Files Error: ' . $e->getMessage());
            throw new \Exception('Failed to list files: ' . $e->getMessage());
        }
    }

    public function downloadFile(array $credentials, string $siteId, string $itemId): array
    {
        try {
            error_log('Downloading SharePoint file - SiteID: ' . $siteId . ', ItemID: ' . $itemId);
            
            // Get file metadata first
            $metadataResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/drive/items/{$itemId}", [
                'auth_bearer' => $credentials['access_token'],
            ]);
            
            $metadata = $metadataResponse->toArray();
            
            // Get download URL
            $downloadResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/drive/items/{$itemId}/content", [
                'auth_bearer' => $credentials['access_token'],
                'max_redirects' => 0,
            ]);
            
            // The download URL is in the Location header
            $downloadUrl = $downloadResponse->getHeaders(false)['location'][0] ?? null;
            
            error_log('SharePoint file download prepared successfully: ' . ($metadata['name'] ?? 'unknown'));
            
            return [
                'metadata' => $metadata,
                'downloadUrl' => $downloadUrl,
                'name' => $metadata['name'] ?? 'file',
                'size' => $metadata['size'] ?? 0,
                'mimeType' => $metadata['file']['mimeType'] ?? 'application/octet-stream'
            ];
            
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint Download File Client Error (' . $statusCode . '): ' . $errorContent);
            
            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to download SharePoint file (HTTP ' . $statusCode . ')');
            
        } catch (\Exception $e) {
            error_log('SharePoint Download File Error: ' . $e->getMessage());
            throw new \Exception('Failed to download file: ' . $e->getMessage());
        }
    }

    public function getListItems(array $credentials, string $siteId, string $listId, array $filters = []): array
    {
        try {
            error_log('Getting SharePoint list items - SiteID: ' . $siteId . ', ListID: ' . $listId);
            
            $query = [
                '$expand' => 'fields',
                '$top' => 100
            ];
            
            if (!empty($filters['filter'])) {
                $query['$filter'] = $filters['filter'];
                error_log('Applying filter: ' . $filters['filter']);
            }
            
            if (!empty($filters['orderby'])) {
                $query['$orderby'] = $filters['orderby'];
            }
            
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/lists/{$listId}/items", [
                'auth_bearer' => $credentials['access_token'],
                'query' => $query
            ]);

            $data = $response->toArray();
            error_log('SharePoint list items retrieved successfully, count: ' . (isset($data['value']) ? count($data['value']) : 0));
            return $data;
            
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint Get List Items Client Error (' . $statusCode . '): ' . $errorContent);
            
            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to get SharePoint list items (HTTP ' . $statusCode . ')');
            
        } catch (\Exception $e) {
            error_log('SharePoint Get List Items Error: ' . $e->getMessage());
            throw new \Exception('Failed to get list items: ' . $e->getMessage());
        }
    }

    public function refreshToken(string $refreshToken, string $clientId, string $clientSecret, string $tenantId): array
    {
        $response = $this->httpClient->request('POST', "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'body' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'scope' => 'https://graph.microsoft.com/.default offline_access'
            ]
        ]);

        $data = $response->toArray();
        
        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => $data['expires_in'],
            'expires_at' => time() + $data['expires_in']
        ];
    }

    public function getSiteId(array $credentials, string $siteUrl): ?string
    {
        try {
            // Parse the site URL to get the hostname and path
            $parsedUrl = parse_url($siteUrl);
            $hostname = $parsedUrl['host'] ?? '';
            $path = trim($parsedUrl['path'] ?? '', '/');
            
            // Construct the API endpoint
            if ($path) {
                $endpoint = "/sites/{$hostname}:/sites/{$path}";
            } else {
                $endpoint = "/sites/{$hostname}";
            }
            
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . $endpoint, [
                'auth_bearer' => $credentials['access_token'],
            ]);
            
            $data = $response->toArray();
            return $data['id'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract readable content from SharePoint page canvasLayout
     * 
     * @param array $pageData The page data with expanded canvasLayout
     * @param int $maxLength Maximum length of content to return (default 1000)
     * @return string|null The extracted content or null if no content found
     */
    private function extractPageContent(array $pageData, int $maxLength = 1000): ?string
    {
        $content = '';
        
        // Check if canvasLayout exists
        if (!isset($pageData['canvasLayout'])) {
            return null;
        }
        
        $canvasLayout = $pageData['canvasLayout'];
        
        // Process horizontal sections
        if (isset($canvasLayout['horizontalSections']) && is_array($canvasLayout['horizontalSections'])) {
            foreach ($canvasLayout['horizontalSections'] as $section) {
                if (isset($section['columns']) && is_array($section['columns'])) {
                    foreach ($section['columns'] as $column) {
                        if (isset($column['webparts']) && is_array($column['webparts'])) {
                            foreach ($column['webparts'] as $webpart) {
                                // Extract content from text web parts
                                if (isset($webpart['innerHtml'])) {
                                    // Strip HTML tags to get plain text
                                    $text = strip_tags($webpart['innerHtml']);
                                    // Normalize whitespace
                                    $text = preg_replace('/\s+/', ' ', trim($text));
                                    if (!empty($text)) {
                                        $content .= $text . ' ';
                                    }
                                }
                                
                                // Also check for other content types in data field
                                if (isset($webpart['data']['properties']['title'])) {
                                    $content .= $webpart['data']['properties']['title'] . ' ';
                                }
                                if (isset($webpart['data']['description'])) {
                                    $content .= $webpart['data']['description'] . ' ';
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Process vertical section if exists
        if (isset($canvasLayout['verticalSection']['webparts']) && is_array($canvasLayout['verticalSection']['webparts'])) {
            foreach ($canvasLayout['verticalSection']['webparts'] as $webpart) {
                if (isset($webpart['innerHtml'])) {
                    $text = strip_tags($webpart['innerHtml']);
                    $text = preg_replace('/\s+/', ' ', trim($text));
                    if (!empty($text)) {
                        $content .= $text . ' ';
                    }
                }
            }
        }
        
        // Trim and limit content length
        $content = trim($content);
        
        if (empty($content)) {
            return null;
        }
        
        // Truncate to maxLength if necessary
        if (strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength) . '...';
        }
        
        return $content;
    }
}