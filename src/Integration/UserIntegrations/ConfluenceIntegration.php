<?php

namespace App\Integration\UserIntegrations;

use App\Integration\IntegrationInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\ConfluenceService;

class ConfluenceIntegration implements IntegrationInterface
{
    public function __construct(
        private ConfluenceService $confluenceService
    ) {}

    public function getType(): string
    {
        return 'confluence';
    }

    public function getName(): string
    {
        return 'Confluence';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'confluence_search',
                'Search for Confluence pages using CQL (Confluence Query Language)',
                [
                    [
                        'name' => 'cql',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'CQL query string'
                    ],
                    [
                        'name' => 'maxResults',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50)'
                    ]
                ]
            ),
            new ToolDefinition(
                'confluence_get_page',
                'Get detailed content of a specific Confluence page',
                [
                    [
                        'name' => 'pageId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Page ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'confluence_get_comments',
                'Get comments from a specific Confluence page',
                [
                    [
                        'name' => 'pageId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Page ID'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('Confluence integration requires credentials');
        }

        return match($toolName) {
            'confluence_search' => $this->confluenceService->search(
                $credentials,
                $parameters['cql'],
                $parameters['maxResults'] ?? 50
            ),
            'confluence_get_page' => $this->confluenceService->getPage(
                $credentials,
                $parameters['pageId']
            ),
            'confluence_get_comments' => $this->confluenceService->getComments(
                $credentials,
                $parameters['pageId']
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
        return isset($credentials['url'])
            && isset($credentials['username'])
            && isset($credentials['api_token']);
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'url',
                'url',
                'Confluence URL',
                'https://your-domain.atlassian.net',
                true,
                'Your Confluence instance URL'
            ),
            new CredentialField(
                'username',
                'email',
                'Email',
                'your-email@example.com',
                true,
                'Email address associated with your Confluence account'
            ),
            new CredentialField(
                'api_token',
                'password',
                'API Token',
                null,
                true,
                'Your Confluence API token (not your password)'
            ),
        ];
    }
}