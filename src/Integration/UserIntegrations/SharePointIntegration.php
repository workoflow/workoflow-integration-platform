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
    ) {}

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
                'Search for documents in SharePoint',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 25)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_search_pages',
                'Search for pages in SharePoint',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 25)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_read_page',
                'Read a specific SharePoint page content',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID'
                    ],
                    [
                        'name' => 'pageId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Page ID'
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

        return match($toolName) {
            'sharepoint_search_documents' => $this->sharePointService->searchDocuments(
                $credentials,
                $parameters['query'],
                $parameters['limit'] ?? 25
            ),
            'sharepoint_search_pages' => $this->sharePointService->searchPages(
                $credentials,
                $parameters['query'],
                $parameters['limit'] ?? 25
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
}