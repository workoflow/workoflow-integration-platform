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
    ) {
    }

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
                        'description' => 'CQL query string. Examples: id=123456 (by page ID), title~"Meeting notes" (by title), space=AT AND text~"search term" (in space with text), type=page AND lastModified>=2024-01-01 (pages modified after date). Note: url= is not a valid CQL field.'
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
            ),
            new ToolDefinition(
                'confluence_create_page',
                'Create a new page in Confluence. Supports markdown, plain text, or HTML content formats for AI-friendly page creation.',
                [
                    [
                        'name' => 'spaceKey',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Space key like \'PROJ\', \'IT\', or \'MARKETING\'. Either spaceKey or spaceId is required. Use confluence_search with type=space to find available spaces.'
                    ],
                    [
                        'name' => 'spaceId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Space ID (numeric). Either spaceKey or spaceId is required. If you know the space key, use that instead for easier usage.'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Page title, e.g., \'Q3 Planning Meeting Notes\', \'API Documentation\', or \'Team Retrospective - Sprint 42\''
                    ],
                    [
                        'name' => 'content',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Page content. Supports markdown (# Headers, **bold**, *italic*, [links](url), etc.), plain text, or HTML. Example: \'# Meeting Notes\\n\\n## Attendees\\n* John\\n* Jane\\n\\n## Action Items\\n1. Review budget\\n2. Update timeline\''
                    ],
                    [
                        'name' => 'contentFormat',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Format of the content: \'markdown\' (default), \'plain\', \'html\', or \'storage\' (Confluence native format). Most AI agents should use \'markdown\' for best results.'
                    ],
                    [
                        'name' => 'parentId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Parent page ID to create this page as a child. Leave empty to create at space root. Use confluence_search to find parent pages.'
                    ],
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Page status: \'current\' (published, default) or \'draft\' (not published). Use \'draft\' for pages that need review before publishing.'
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

        return match ($toolName) {
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
            'confluence_create_page' => $this->confluenceService->createPage(
                $credentials,
                $parameters
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
                'https://your-domain.atlassian.net/wiki',
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
                'Your Confluence API token (not your password). <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" rel="noopener">Create API token</a>'
            ),
        ];
    }
}
