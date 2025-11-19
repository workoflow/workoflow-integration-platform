<?php

namespace App\Integration\UserIntegrations;

use App\Integration\IntegrationInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\SharePointService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;

class SharePointIntegration implements IntegrationInterface
{
    public function __construct(
        private SharePointService $sharePointService,
        private EncryptionService $encryptionService,
        private EntityManagerInterface $entityManager
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
                'Search for documents in SharePoint with content summaries. Supports multi-keyword queries (comma-separated or space-separated). Returns results grouped by type (Files, Sites, Pages, Lists) similar to SharePoint\'s native search.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query to find relevant documents. Can be a single keyword, multiple keywords separated by commas or spaces, or exact phrases in quotes. Examples: "Urlaub", "Urlaubsregelung, Ferien", "API documentation"'
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
                'Search for pages and content across SharePoint using Microsoft Graph Search API. Supports multi-keyword queries with automatic KQL (Keyword Query Language) formatting. Returns comprehensive results grouped by type (Files, Sites, Pages, Lists, Drives) matching SharePoint\'s native search experience.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query supporting multiple formats: single keywords, comma-separated keywords (e.g., "Urlaub, Ferien, Abwesenheit"), exact phrases in quotes (e.g., "vacation policy"), or natural language. The query will be automatically converted to proper KQL syntax for optimal search results.'
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
                'Extract and read text content from SharePoint documents (Word, Excel, PowerPoint, PDF, text files)',
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
                'Read content from a SharePoint page',
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
                'List files in a SharePoint directory',
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
                'Download a file from SharePoint',
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
                'Get items from a SharePoint list',
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
            'sharepoint_search_documents' => $this->sharePointService->searchDocuments(
                $credentials,
                $this->parseQueryToKQL($parameters['query']),
                min($parameters['limit'] ?? 10, 25)
            ),
            'sharepoint_search_pages' => $this->sharePointService->searchPages(
                $credentials,
                $this->parseQueryToKQL($parameters['query']),
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
