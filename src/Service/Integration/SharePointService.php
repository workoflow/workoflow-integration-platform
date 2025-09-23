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
            error_log('SharePoint searchPages called with query: ' . $query . ', limit: ' . $limit);
            $results = [];
            
            // Use the Microsoft Graph Search API
            $searchRequest = [
                'requests' => [
                    [
                        'entityTypes' => ['listItem', 'site', 'driveItem'],
                        'query' => [
                            'queryString' => $query
                        ],
                        'size' => $limit,
                        'fields' => [
                            'title',
                            'name', 
                            'webUrl',
                            'description',
                            'createdDateTime',
                            'lastModifiedDateTime',
                            'siteId',
                            'id'
                        ]
                    ]
                ]
            ];
            
            error_log('Attempting Microsoft Graph Search API with query: ' . json_encode($searchRequest));
            
            $searchResponse = $this->httpClient->request('POST', self::GRAPH_API_BASE . '/search/query', [
                'auth_bearer' => $credentials['access_token'],
                'json' => $searchRequest
            ]);
            
            $searchData = $searchResponse->toArray();
            error_log('Search API full response: ' . json_encode($searchData));
            
            if (isset($searchData['value'][0]['hitsContainers'])) {
                foreach ($searchData['value'][0]['hitsContainers'] as $container) {
                    error_log('Processing container with entityType: ' . ($container['@odata.type'] ?? 'unknown'));
                    error_log('Container has ' . (isset($container['hits']) ? count($container['hits']) : 0) . ' hits');
                    
                    if (isset($container['hits'])) {
                        foreach ($container['hits'] as $hit) {
                            if (isset($hit['resource'])) {
                                $resource = $hit['resource'];
                                error_log('Hit resource type: ' . ($resource['@odata.type'] ?? 'unknown'));
                                
                                // Log all available fields for debugging
                                error_log('Resource fields: ' . json_encode(array_keys($resource)));
                                
                                // More flexible filtering - include any SharePoint content
                                // Determine the type based on @odata.type
                                $odataType = $resource['@odata.type'] ?? 'unknown';
                                $resultType = 'page'; // default

                                if (str_contains($odataType, 'driveItem')) {
                                    $resultType = 'document';
                                } elseif (str_contains($odataType, 'site')) {
                                    $resultType = 'site';
                                } elseif (str_contains($odataType, 'listItem')) {
                                    $resultType = 'page';
                                }

                                // Extract siteId properly based on the resource type
                                $extractedSiteId = $resource['siteId'] ?? '';

                                // For driveItems, try to extract siteId from parentReference if not directly available
                                if (empty($extractedSiteId) && isset($resource['parentReference']['siteId'])) {
                                    $extractedSiteId = $resource['parentReference']['siteId'];
                                }

                                // If still no siteId and we have a webUrl, try to extract it
                                if (empty($extractedSiteId) && !empty($resource['webUrl'])) {
                                    // Try to extract site ID from the webUrl
                                    // SharePoint URLs typically follow: https://domain.sharepoint.com/sites/sitename/...
                                    if (preg_match('/https:\/\/[^\/]+\.sharepoint\.com\/sites\/([^\/]+)/', $resource['webUrl'], $matches)) {
                                        // We have the site name, but need to note this for the user
                                        $extractedSiteId = $matches[1]; // This is actually a site name, not ID
                                        error_log('Warning: Using site name "' . $extractedSiteId . '" extracted from URL as siteId placeholder');
                                    }
                                }

                                $results[] = [
                                    'type' => $resultType,
                                    'id' => $resource['id'] ?? '',
                                    'title' => $resource['title'] ?? $resource['name'] ?? $hit['summary'] ?? '',
                                    'name' => $resource['name'] ?? '',
                                    'webUrl' => $resource['webUrl'] ?? '',
                                    'description' => $resource['description'] ?? $hit['summary'] ?? '',
                                    'createdDateTime' => $resource['createdDateTime'] ?? '',
                                    'lastModifiedDateTime' => $resource['lastModifiedDateTime'] ?? '',
                                    'siteId' => $extractedSiteId,
                                    'siteName' => $resource['displayName'] ?? '',
                                    'siteUrl' => $resource['webUrl'] ?? '',
                                    'summary' => $hit['summary'] ?? '',
                                    'resourceType' => $odataType,
                                    'parentReference' => $resource['parentReference'] ?? null
                                ];
                            }
                        }
                    }
                }
            } else {
                error_log('No hitsContainers found in search response');
                error_log('Response structure: ' . json_encode(array_keys($searchData)));
            }
            
            error_log('Search API found ' . count($results) . ' results');
            
            return [
                'value' => $results,
                'count' => count($results),
                'searchQuery' => $query
            ];
            
        } catch (\Exception $e) {
            error_log('SharePoint Pages Search Error: ' . $e->getMessage());
            error_log('Error trace: ' . $e->getTraceAsString());
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
            
            // Check if pageId looks like a name/title rather than a GUID
            // A proper page ID is a GUID format like "7b6fd3e8-80ad-4392-9643-6bab61be81e0"
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $pageId)) {
                error_log('PageID appears to be a name/title, attempting to resolve to actual page ID');
                
                // Get all pages from the site and find the one matching the name/title
                $pagesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/pages", [
                    'auth_bearer' => $credentials['access_token'],
                    'query' => [
                        '$top' => 100  // Get more pages to find the right one
                    ]
                ]);
                
                $pages = $pagesResponse->toArray();
                
                if (isset($pages['value'])) {
                    $resolvedPageId = null;
                    $pageNameLower = strtolower($pageId);
                    
                    // Try to find exact match first by title or name
                    foreach ($pages['value'] as $page) {
                        // Check title match
                        if (isset($page['title']) && strtolower($page['title']) === $pageNameLower) {
                            $resolvedPageId = $page['id'];
                            error_log('Found exact page match by title: ' . $page['title'] . ' -> ' . $resolvedPageId);
                            break;
                        }
                        // Check name match
                        if (isset($page['name']) && strtolower($page['name']) === $pageNameLower) {
                            $resolvedPageId = $page['id'];
                            error_log('Found exact page match by name: ' . $page['name'] . ' -> ' . $resolvedPageId);
                            break;
                        }
                        // Check for URL-friendly name match (with hyphens instead of spaces)
                        $urlFriendlyPageId = str_replace('-', ' ', $pageId);
                        if (isset($page['title']) && strtolower($page['title']) === strtolower($urlFriendlyPageId)) {
                            $resolvedPageId = $page['id'];
                            error_log('Found page match by URL-friendly title: ' . $page['title'] . ' -> ' . $resolvedPageId);
                            break;
                        }
                    }
                    
                    // If no exact match, try partial match
                    if (!$resolvedPageId) {
                        foreach ($pages['value'] as $page) {
                            if ((isset($page['title']) && str_contains(strtolower($page['title']), $pageNameLower)) ||
                                (isset($page['name']) && str_contains(strtolower($page['name']), $pageNameLower))) {
                                $resolvedPageId = $page['id'];
                                $pageTitle = $page['title'] ?? $page['name'] ?? 'Unknown';
                                error_log('Using partial page match: ' . $pageTitle . ' -> ' . $resolvedPageId);
                                break;
                            }
                        }
                    }
                    
                    if ($resolvedPageId) {
                        $pageId = $resolvedPageId;
                    } else {
                        throw new \Exception('Could not resolve page name "' . $pageId . '" to a valid page ID. Available pages: ' . 
                            implode(', ', array_map(function($p) { return $p['title'] ?? $p['name'] ?? 'Untitled'; }, $pages['value'])));
                    }
                } else {
                    throw new \Exception('No pages found in site');
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
        // Use 'common' endpoint for multi-tenant apps, or specific tenant if stored
        // 'common' allows tokens from any tenant to be refreshed
        $endpoint = ($tenantId === 'common' || empty($tenantId))
            ? 'common'
            : $tenantId;

        $response = $this->httpClient->request('POST', "https://login.microsoftonline.com/{$endpoint}/oauth2/v2.0/token", [
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
     * Get site information by site ID
     * 
     * @param array $credentials The authentication credentials
     * @param string $siteId The site ID
     * @return array Site information
     */
    private function getSiteInfo(array $credentials, string $siteId): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites/' . $siteId, [
                'auth_bearer' => $credentials['access_token'],
            ]);
            
            return $response->toArray();
        } catch (\Exception $e) {
            error_log('Failed to get site info for ' . $siteId . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Read document content from SharePoint
     * Extracts text from Office documents (Word, Excel, PowerPoint) and PDFs
     *
     * @param array $credentials Authentication credentials
     * @param string $siteId Site ID
     * @param string $itemId Document item ID
     * @param int $maxLength Maximum content length to return (default 5000)
     * @return array Document content and metadata
     */
    public function readDocument(array $credentials, string $siteId, string $itemId, int $maxLength = 5000): array
    {
        try {
            error_log('Reading SharePoint document content - SiteID: ' . $siteId . ', ItemID: ' . $itemId);

            // Check if siteId needs to be resolved (if it's a site name rather than ID)
            // A proper site ID contains commas or periods (e.g., "contoso.sharepoint.com,guid,guid")
            if (!str_contains($siteId, ',') && !str_contains($siteId, '.')) {
                error_log('SiteID appears to be a site name, attempting to resolve to actual site ID');

                // Try to resolve the site name to a proper site ID
                try {
                    $sitesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites', [
                        'auth_bearer' => $credentials['access_token'],
                        'query' => [
                            'search' => $siteId,
                            '$top' => 5
                        ]
                    ]);

                    $sites = $sitesResponse->toArray();

                    if (isset($sites['value']) && count($sites['value']) > 0) {
                        // Try to find exact match first
                        foreach ($sites['value'] as $site) {
                            if ((isset($site['displayName']) && strcasecmp($site['displayName'], $siteId) === 0) ||
                                (isset($site['name']) && strcasecmp($site['name'], $siteId) === 0)) {
                                $siteId = $site['id'];
                                error_log('Resolved site name to ID: ' . $siteId);
                                break;
                            }
                        }

                        // If no exact match, use the first result as fallback
                        if (!str_contains($siteId, ',')) {
                            $siteId = $sites['value'][0]['id'];
                            error_log('Using first matching site ID: ' . $siteId);
                        }
                    } else {
                        error_log('Warning: Could not resolve site name "' . $siteId . '" to a site ID, proceeding anyway');
                    }
                } catch (\Exception $e) {
                    error_log('Failed to resolve site name: ' . $e->getMessage());
                    // Continue with the original siteId
                }
            }

            // First get file metadata to understand what we're dealing with
            $metadataResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/drive/items/{$itemId}", [
                'auth_bearer' => $credentials['access_token'],
            ]);

            $metadata = $metadataResponse->toArray();
            $fileName = $metadata['name'] ?? 'unknown';
            $mimeType = $metadata['file']['mimeType'] ?? 'unknown';
            $size = $metadata['size'] ?? 0;

            error_log('Document details: ' . $fileName . ' (' . $mimeType . ', ' . $size . ' bytes)');

            // For very large files, provide metadata only with size warning
            if ($size > 10485760) { // 10MB
                return [
                    'warning' => 'Document is very large (' . round($size / 1048576, 1) . ' MB). Content extraction limited to first part of document.',
                    'metadata' => $metadata,
                    'content' => $this->extractFirstPartOfLargeDocument($credentials, $siteId, $itemId, $maxLength),
                    'contentLength' => $maxLength,
                    'truncated' => true,
                    'fullSize' => $size
                ];
            }

            // Determine if we can extract text based on mime type
            $supportedTypes = [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
                'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .pptx
                'application/pdf', // .pdf
                'text/plain', // .txt
                'text/csv', // .csv
                'application/msword', // .doc (legacy)
                'application/vnd.ms-excel', // .xls (legacy)
                'application/vnd.ms-powerpoint', // .ppt (legacy)
            ];

            $canExtractText = false;
            foreach ($supportedTypes as $type) {
                if (str_contains($mimeType, $type) || str_contains($mimeType, 'text/')) {
                    $canExtractText = true;
                    break;
                }
            }

            if (!$canExtractText) {
                return [
                    'error' => 'File type does not support text extraction',
                    'metadata' => $metadata,
                    'mimeType' => $mimeType,
                    'content' => null,
                    'contentLength' => 0,
                    'truncated' => false
                ];
            }

            // For text files, we can get content directly
            if (str_contains($mimeType, 'text/')) {
                $contentResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/drive/items/{$itemId}/content", [
                    'auth_bearer' => $credentials['access_token'],
                ]);

                $content = $contentResponse->getContent();

                // Truncate if needed
                $truncated = false;
                if (strlen($content) > $maxLength) {
                    $content = substr($content, 0, $maxLength);
                    $truncated = true;
                }

                return [
                    'metadata' => $metadata,
                    'content' => $content,
                    'contentLength' => strlen($content),
                    'truncated' => $truncated,
                    'mimeType' => $mimeType
                ];
            }

            // For Office documents and PDFs, try to get converted content
            // Microsoft Graph can convert these to text format on the fly
            try {
                // Use the content endpoint with format parameter for conversion
                // Note: This requires the file to be in SharePoint/OneDrive (not external storage)
                $contentResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/drive/items/{$itemId}/content", [
                    'auth_bearer' => $credentials['access_token'],
                    'query' => [
                        'format' => 'text' // Request text conversion
                    ],
                    'headers' => [
                        'Accept' => 'text/plain' // Request plain text response
                    ]
                ]);

                $content = $contentResponse->getContent();

                // If we get HTML back instead of text, try to extract text from it
                if (str_contains($content, '<html') || str_contains($content, '<body')) {
                    $content = strip_tags($content);
                    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                // Clean up the content
                $content = preg_replace('/\s+/', ' ', trim($content));

                // Truncate if needed
                $truncated = false;
                if (strlen($content) > $maxLength) {
                    $content = substr($content, 0, $maxLength);
                    $truncated = true;
                }

                error_log('Document content extracted successfully (length: ' . strlen($content) . ')');

                return [
                    'metadata' => $metadata,
                    'content' => $content,
                    'contentLength' => strlen($content),
                    'truncated' => $truncated,
                    'mimeType' => $mimeType
                ];

            } catch (\Exception $conversionError) {
                // If text conversion fails, we might still be able to get raw content
                error_log('Text conversion failed, attempting raw content: ' . $conversionError->getMessage());

                // As a fallback, download the raw file content
                // This won't be text, but we return the error gracefully
                return [
                    'error' => 'Could not extract text from document. Document may be encrypted, corrupted, or in an unsupported format.',
                    'metadata' => $metadata,
                    'mimeType' => $mimeType,
                    'content' => null,
                    'contentLength' => 0,
                    'truncated' => false
                ];
            }

        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint Read Document Client Error (' . $statusCode . '): ' . $errorContent);

            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to read SharePoint document (HTTP ' . $statusCode . ')');

        } catch (\Exception $e) {
            error_log('SharePoint Read Document Error: ' . $e->getMessage());
            throw new \Exception('Failed to read document: ' . $e->getMessage());
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

    /**
     * Extract first part of content from large documents
     * Uses byte range requests to avoid downloading entire file
     *
     * @param array $credentials Authentication credentials
     * @param string $siteId Site ID
     * @param string $itemId Document item ID
     * @param int $maxLength Maximum characters to extract
     * @return string|null Extracted content or null if extraction fails
     */
    private function extractFirstPartOfLargeDocument(array $credentials, string $siteId, string $itemId, int $maxLength): ?string
    {
        try {
            // For large files, we can try to get just the first part using Range headers
            // This is a best-effort approach - not all file types support partial content extraction
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/drive/items/{$itemId}/content", [
                'auth_bearer' => $credentials['access_token'],
                'headers' => [
                    'Range' => 'bytes=0-524288', // Get first 512KB only
                    'Accept' => 'text/plain'
                ],
                'timeout' => 30 // Limit timeout for large files
            ]);

            $partialContent = $response->getContent();

            // Try to extract text from the partial content
            if (str_contains($partialContent, '<html') || str_contains($partialContent, '<body')) {
                $partialContent = strip_tags($partialContent);
                $partialContent = html_entity_decode($partialContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            // Clean and truncate
            $partialContent = preg_replace('/\s+/', ' ', trim($partialContent));
            if (strlen($partialContent) > $maxLength) {
                $partialContent = substr($partialContent, 0, $maxLength);
            }

            return $partialContent ?: 'Unable to extract content from large document';

        } catch (\Exception $e) {
            error_log('Failed to extract content from large document: ' . $e->getMessage());
            return 'Unable to extract content from large document (size limit exceeded)';
        }
    }
}