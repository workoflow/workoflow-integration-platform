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
            // Get all sites the user has access to
            $sitesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    'search' => $query,
                    '$top' => $limit
                ]
            ]);
            
            $sites = $sitesResponse->toArray();
            $results = [];
            
            // If we got sites matching the search, return them
            if (isset($sites['value'])) {
                foreach ($sites['value'] as $site) {
                    $results[] = [
                        'type' => 'site',
                        'id' => $site['id'] ?? '',
                        'name' => $site['displayName'] ?? $site['name'] ?? '',
                        'webUrl' => $site['webUrl'] ?? '',
                        'description' => $site['description'] ?? ''
                    ];
                }
            }
            
            // Also try to search in the root SharePoint site's drive for documents
            try {
                $driveSearchResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/drive/search(q=\'' . urlencode($query) . '\')', [
                    'auth_bearer' => $credentials['access_token'],
                    'query' => [
                        '$top' => $limit
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
                // Drive search might fail if user doesn't have OneDrive access
                error_log('Drive search failed: ' . $e->getMessage());
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

    public function readPage(array $credentials, string $siteId, string $pageId): array
    {
        $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/pages/{$pageId}", [
            'auth_bearer' => $credentials['access_token'],
            'query' => [
                '$expand' => 'canvasLayout'
            ]
        ]);

        return $response->toArray();
    }

    public function listFiles(array $credentials, string $siteId, string $path = ''): array
    {
        $endpoint = $path 
            ? "/sites/{$siteId}/drive/root:/{$path}:/children"
            : "/sites/{$siteId}/drive/root/children";
            
        $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . $endpoint, [
            'auth_bearer' => $credentials['access_token'],
            'query' => [
                '$select' => 'id,name,size,lastModifiedDateTime,file,folder,webUrl',
                '$top' => 100
            ]
        ]);

        return $response->toArray();
    }

    public function downloadFile(array $credentials, string $siteId, string $itemId): array
    {
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
        
        return [
            'metadata' => $metadata,
            'downloadUrl' => $downloadUrl,
            'name' => $metadata['name'] ?? 'file',
            'size' => $metadata['size'] ?? 0,
            'mimeType' => $metadata['file']['mimeType'] ?? 'application/octet-stream'
        ];
    }

    public function getListItems(array $credentials, string $siteId, string $listId, array $filters = []): array
    {
        $query = [
            '$expand' => 'fields',
            '$top' => 100
        ];
        
        if (!empty($filters['filter'])) {
            $query['$filter'] = $filters['filter'];
        }
        
        if (!empty($filters['orderby'])) {
            $query['$orderby'] = $filters['orderby'];
        }
        
        $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/lists/{$listId}/items", [
            'auth_bearer' => $credentials['access_token'],
            'query' => $query
        ]);

        return $response->toArray();
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
}