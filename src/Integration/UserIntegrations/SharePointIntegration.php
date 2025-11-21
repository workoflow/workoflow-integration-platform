<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\SharePointService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

class SharePointIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private SharePointService $sharePointService,
        private EncryptionService $encryptionService,
        private EntityManagerInterface $entityManager,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'sharepoint';
    }

    public function getName(): string
    {
        return 'SharePoint';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'sharepoint_search_documents',
                'Search for documents in SharePoint with content summaries. Supports multi-keyword queries (comma-separated or space-separated) and filename filtering. Returns: Array of search results grouped by type (Files, Sites, Pages, Lists) with each result containing hitId, rank, summary, and resource object. Resource includes: id, name, size, webUrl, createdDateTime, lastModifiedDateTime, createdBy, lastModifiedBy, parentReference (with siteId, driveId, sharepointIds).',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query to find relevant documents. Supports: (1) Keywords: "Urlaub, Ferien" searches content and filenames. (2) Filename filtering: "filename:Urlaub, filename:Urlaubsregelung" returns only files with these terms in the filename. (3) Mixed: "filename:Urlaub, vacation policy" searches for vacation policy but filters to files with "Urlaub" in filename. (4) Exact phrases: "\"vacation policy\"" for exact match.'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results to return per result type (default: 10, max: 25)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_search_pages',
                'Search for pages and content across SharePoint using Microsoft Graph Search API. Supports multi-keyword queries with automatic KQL formatting and filename filtering. Returns: Array of search results grouped by type (Files, Sites, Pages, Lists, Drives) with each result containing hitId, rank, summary, and resource object. Resource includes: id, name, webUrl, createdDateTime, lastModifiedDateTime, createdBy, lastModifiedBy, size, fileSystemInfo, parentReference.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query supporting: (1) Keywords: "Urlaub, Ferien, Abwesenheit" searches all content. (2) Filename filtering: "filename:Urlaub, filename:Vacation" returns only items with these terms in the name. (3) Exact phrases: "\"vacation policy\"" for exact match. (4) Mixed queries combine content search with filename filtering. The query is automatically optimized for Microsoft Graph Search.'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results per result type (default: 25, max: 50). Results are grouped by type, so total results may exceed this limit.'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_read_document',
                'Extract and read text content from SharePoint documents (Word, Excel, PowerPoint, PDF, text files). Returns: Object containing extracted text content, metadata including fileName, fileSize, mimeType, and documentInfo with title, author, pageCount, wordCount (when available).',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID where the document is stored (from search result\'s siteId field, or site name if ID not available)'
                    ],
                    [
                        'name' => 'itemId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Document item ID from search results (use the id field from search results)'
                    ],
                    [
                        'name' => 'maxLength',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum characters to extract (default: 5000, max: 50000)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_read_page',
                'Read content from a SharePoint page. Returns: Object containing page data with properties: id, name, title, webUrl, createdDateTime, lastModifiedDateTime, lastModifiedBy, webParts array (containing page sections and content blocks).',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID or site name'
                    ],
                    [
                        'name' => 'pageId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Page ID or page title'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_list_files',
                'List files in a SharePoint directory. Returns: Array of driveItem objects with properties: id, name, size, webUrl, createdDateTime, lastModifiedDateTime, createdBy, lastModifiedBy, file (with mimeType and hashes), folder (if item is a folder), parentReference.',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID'
                    ],
                    [
                        'name' => 'path',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Directory path (optional)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_download_file',
                'Download a file from SharePoint. Returns: Binary file content stream or redirect URL to pre-authenticated download location. Response includes Content-Type header with file MIME type and Content-Disposition header with filename.',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID'
                    ],
                    [
                        'name' => 'itemId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Item ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_get_list_items',
                'Get items from a SharePoint list. Returns: Array of listItem objects with properties: id, createdDateTime, lastModifiedDateTime, createdBy, lastModifiedBy, fields (containing custom column values like Title, Employee, ID, etc.), contentType, eTag, webUrl.',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID'
                    ],
                    [
                        'name' => 'listId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'List ID'
                    ],
                    [
                        'name' => 'filters',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Optional filters'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('SharePoint integration requires credentials');
        }

        // Handle token refresh if needed
        if (isset($credentials['expires_at']) && time() >= $credentials['expires_at']) {
            $credentials = $this->refreshTokenIfNeeded($credentials);
        }

        return match ($toolName) {
            'sharepoint_search_documents' => $this->executeSearchWithFilenameFilter(
                'searchDocuments',
                $credentials,
                $parameters['query'],
                min($parameters['limit'] ?? 10, 25)
            ),
            'sharepoint_search_pages' => $this->executeSearchWithFilenameFilter(
                'searchPages',
                $credentials,
                $parameters['query'],
                min($parameters['limit'] ?? 25, 50)
            ),
            'sharepoint_read_document' => $this->sharePointService->readDocument(
                $credentials,
                $parameters['siteId'],
                $parameters['itemId'],
                min($parameters['maxLength'] ?? 5000, 50000)
            ),
            'sharepoint_read_page' => $this->sharePointService->readPage(
                $credentials,
                $parameters['siteId'],
                $parameters['pageId']
            ),
            'sharepoint_list_files' => $this->sharePointService->listFiles(
                $credentials,
                $parameters['siteId'],
                $parameters['path'] ?? ''
            ),
            'sharepoint_download_file' => $this->sharePointService->downloadFile(
                $credentials,
                $parameters['siteId'],
                $parameters['itemId']
            ),
            'sharepoint_get_list_items' => $this->sharePointService->getListItems(
                $credentials,
                $parameters['siteId'],
                $parameters['listId'],
                $parameters['filters'] ?? []
            ),
            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        return isset($credentials['access_token'])
            && isset($credentials['tenant_id']);
    }

    public function getCredentialFields(): array
    {
        // SharePoint uses OAuth2, so we return a special oauth type field
        return [
            new CredentialField(
                'oauth',
                'oauth',
                'Connect with Microsoft',
                null,
                true,
                'Authenticate with your Microsoft account to access SharePoint'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/sharepoint_full.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }

    private function refreshTokenIfNeeded(array $credentials): array
    {
        if (!isset($credentials['refresh_token'])) {
            throw new \Exception('SharePoint token expired and no refresh token available');
        }

        $newTokens = $this->sharePointService->refreshToken(
            $credentials['refresh_token'],
            $credentials['client_id'],
            $credentials['client_secret'],
            $credentials['tenant_id']
        );

        return array_merge($credentials, [
            'access_token' => $newTokens['access_token'],
            'refresh_token' => $newTokens['refresh_token'],
            'expires_at' => $newTokens['expires_at']
        ]);
    }

    /**
     * Execute search with filename pattern filtering
     *
     * Handles queries that include filename: prefixes by:
     * 1. Extracting filename patterns
     * 2. Converting them to searchable keywords
     * 3. Post-filtering results by filename
     *
     * @param string $method Service method to call ('searchDocuments' or 'searchPages')
     * @param array $credentials Authentication credentials
     * @param string $query Raw query possibly containing filename: prefixes
     * @param int $limit Maximum results
     * @return array Search results, filtered by filename if patterns were specified
     */
    private function executeSearchWithFilenameFilter(
        string $method,
        array $credentials,
        string $query,
        int $limit
    ): array {
        // Extract filename patterns and clean keywords
        $parsed = $this->parseQueryWithFilenameSupport($query);
        $cleanQuery = $parsed['query'];
        $filenamePatterns = $parsed['filenamePatterns'];

        // Execute the search with cleaned query
        $results = $this->sharePointService->$method(
            $credentials,
            $this->parseQueryToKQL($cleanQuery),
            $limit * 3 // Fetch more to account for filtering
        );

        // If no filename patterns, return results as-is
        if (empty($filenamePatterns)) {
            return $results;
        }

        // Post-filter results by filename
        $filteredResults = $this->filterResultsByFilename($results, $filenamePatterns);

        // Limit results
        if (isset($filteredResults['value']) && count($filteredResults['value']) > $limit) {
            $filteredResults['value'] = array_slice($filteredResults['value'], 0, $limit);
            $filteredResults['count'] = count($filteredResults['value']);
        }

        // Update grouped results if present
        if (isset($filteredResults['grouped_results'])) {
            $filteredResults['grouped_results'] = $this->regroupResults($filteredResults['value']);
            $filteredResults['grouped_summary'] = $this->createGroupedSummaryFromResults($filteredResults['grouped_results']);
        }

        // Add filter info to response
        $filteredResults['filename_filter_applied'] = true;
        $filteredResults['filename_patterns'] = $filenamePatterns;

        return $filteredResults;
    }

    /**
     * Parse query to extract filename: patterns and return clean searchable query
     *
     * @param string $query Raw query that may contain filename:value patterns
     * @return array ['query' => string, 'filenamePatterns' => array]
     */
    private function parseQueryWithFilenameSupport(string $query): array
    {
        $filenamePatterns = [];
        $cleanParts = [];

        // Split by comma first
        $parts = preg_split('/\s*,\s*/', trim($query));

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Check for filename: prefix (case-insensitive)
            if (preg_match('/^filename:\s*(.+)$/i', $part, $matches)) {
                $pattern = trim($matches[1], '"\'');
                $filenamePatterns[] = $pattern;
                // Also add as search keyword (Microsoft Graph indexes filenames)
                $cleanParts[] = $pattern;
            } else {
                $cleanParts[] = $part;
            }
        }

        return [
            'query' => implode(', ', $cleanParts),
            'filenamePatterns' => array_unique($filenamePatterns)
        ];
    }

    /**
     * Filter search results by filename patterns
     *
     * @param array $results Search results from SharePoint service
     * @param array $filenamePatterns Patterns to match against filename
     * @return array Filtered results
     */
    private function filterResultsByFilename(array $results, array $filenamePatterns): array
    {
        if (!isset($results['value']) || empty($results['value'])) {
            return $results;
        }

        $filtered = array_filter($results['value'], function ($item) use ($filenamePatterns) {
            // Get the filename from various possible fields
            $filename = $item['name'] ?? $item['title'] ?? $item['Name'] ?? '';

            if (empty($filename)) {
                return false;
            }

            // Check if filename contains any of the patterns (case-insensitive)
            foreach ($filenamePatterns as $pattern) {
                if (stripos($filename, $pattern) !== false) {
                    return true;
                }
            }

            return false;
        });

        $originalCount = $results['count'] ?? count($results['value']);
        $results['value'] = array_values($filtered);
        $results['count'] = count($results['value']);
        $results['original_count'] = $originalCount;

        return $results;
    }

    /**
     * Regroup filtered results by type
     *
     * @param array $results Flat array of results
     * @return array Grouped by type
     */
    private function regroupResults(array $results): array
    {
        $grouped = [
            'files' => [],
            'sites' => [],
            'pages' => [],
            'lists' => [],
            'drives' => []
        ];

        foreach ($results as $result) {
            $type = $result['type'] ?? 'unknown';

            switch ($type) {
                case 'file':
                case 'document':
                case 'driveItem':
                    $grouped['files'][] = $result;
                    break;
                case 'site':
                    $grouped['sites'][] = $result;
                    break;
                case 'page':
                    $grouped['pages'][] = $result;
                    break;
                case 'list':
                    $grouped['lists'][] = $result;
                    break;
                case 'drive':
                    $grouped['drives'][] = $result;
                    break;
                default:
                    $grouped['files'][] = $result;
            }
        }

        return array_filter($grouped, fn($group) => !empty($group));
    }

    /**
     * Create grouped summary from grouped results
     *
     * @param array $groupedResults Grouped results
     * @return string Summary string
     */
    private function createGroupedSummaryFromResults(array $groupedResults): string
    {
        $summaryParts = [];
        $typeLabels = [
            'files' => 'Files',
            'sites' => 'Sites',
            'pages' => 'Pages',
            'lists' => 'Lists',
            'drives' => 'Drives'
        ];

        foreach ($groupedResults as $type => $items) {
            if (!empty($items)) {
                $label = $typeLabels[$type] ?? ucfirst($type);
                $summaryParts[] = "$label: " . count($items);
            }
        }

        return implode(', ', $summaryParts);
    }

    /**
     * Parse search query into KQL (Keyword Query Language) format
     *
     * Transforms various query formats into proper KQL syntax for Microsoft Graph Search:
     * - Comma-separated keywords: "Urlaub, Ferien" → "Urlaub OR Ferien"
     * - Multiple spaces: "Urlaub  Ferien" → "Urlaub OR Ferien"
     * - Exact phrases: '"vacation policy"' → '"vacation policy"'
     * - Mixed: "Urlaub, Ferien, \"vacation policy\"" → "Urlaub OR Ferien OR \"vacation policy\""
     *
     * @param string $query Raw search query from user/agent
     * @return string Properly formatted KQL query
     */
    private function parseQueryToKQL(string $query): string
    {
        // Trim whitespace
        $query = trim($query);

        // If query is already in KQL format (contains OR, AND, NOT operators), return as-is
        if (preg_match('/\b(OR|AND|NOT)\b/i', $query)) {
            return $query;
        }

        // Extract exact phrases (anything in quotes)
        $phrases = [];
        $query = preg_replace_callback('/"([^"]+)"/', function ($matches) use (&$phrases) {
            $phrases[] = '"' . $matches[1] . '"';
            return '___PHRASE_' . (count($phrases) - 1) . '___';
        }, $query);

        // Split by comma or multiple spaces
        $keywords = preg_split('/[,\s]+/', $query, -1, PREG_SPLIT_NO_EMPTY);

        // Replace phrase placeholders back
        $keywords = array_map(function ($keyword) use ($phrases) {
            return preg_replace_callback('/___PHRASE_(\d+)___/', function ($matches) use ($phrases) {
                return $phrases[(int)$matches[1]];
            }, $keyword);
        }, $keywords);

        // Remove duplicates and empty values
        $keywords = array_unique(array_filter($keywords));

        // If only one keyword, return as-is
        if (count($keywords) === 1) {
            return $keywords[0];
        }

        // Join with OR operator for KQL
        return implode(' OR ', $keywords);
    }
}
