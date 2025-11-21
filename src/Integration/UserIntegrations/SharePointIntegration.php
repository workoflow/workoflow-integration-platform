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
                'Basic search within user accessible SharePoint sites and personal OneDrive. Searches site-by-site with limited scope. For comprehensive search across ALL SharePoint content, use sharepoint_search_pages instead.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search keywords (comma-separated for multiple). Example: "Urlaub, Ferien, vacation". DO NOT use filename: prefix.'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 10, max: 25)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_search_pages',
                'PRIMARY SEARCH TOOL - Comprehensive search across ALL SharePoint content using Microsoft Graph Search API. Returns results grouped by type (Files, Sites, Pages, Lists, Drives) matching SharePoint native search. Supports KQL (Keyword Query Language) with automatic OR logic for comma-separated keywords.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search keywords (comma-separated for multiple variations). Example: "Urlaub, Ferien, vacation, leave policy". Automatically converted to KQL format. DO NOT use filename: prefix - just use plain keywords.'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum results per type (default: 25, max: 50). Results grouped by Files, Sites, Pages, Lists, Drives.'
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
     * Execute search without filename filtering
     *
     * Previously this method would filter results by filename: patterns,
     * but this caused issues where valid results were excluded.
     * Now it simply cleans the query and passes through to the service.
     *
     * @param string $method Service method to call ('searchDocuments' or 'searchPages')
     * @param array $credentials Authentication credentials
     * @param string $query Raw query (filename: prefixes are now stripped as plain keywords)
     * @param int $limit Maximum results
     * @return array Search results
     */
    private function executeSearchWithFilenameFilter(
        string $method,
        array $credentials,
        string $query,
        int $limit
    ): array {
        // Clean query: strip filename: prefixes and use them as plain keywords
        $cleanQuery = $this->stripFilenamePrefix($query);

        // Execute the search with cleaned query - convert to KQL for searchPages
        $kqlQuery = $this->parseQueryToKQL($cleanQuery);

        return $this->sharePointService->$method(
            $credentials,
            $kqlQuery,
            $limit
        );
    }

    /**
     * Strip filename: prefixes from query and convert to plain keywords
     *
     * @param string $query Raw query that may contain filename:value patterns
     * @return string Clean query with filename: prefixes removed
     */
    private function stripFilenamePrefix(string $query): string
    {
        $cleanParts = [];

        // Split by comma first
        $parts = preg_split('/\s*,\s*/', trim($query));

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Remove filename: prefix if present (case-insensitive)
            if (preg_match('/^filename:\s*(.+)$/i', $part, $matches)) {
                // Add the value without the prefix as a plain keyword
                $cleanParts[] = trim($matches[1], '"\'');
            } else {
                $cleanParts[] = $part;
            }
        }

        // Remove duplicates
        return implode(', ', array_unique($cleanParts));
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
