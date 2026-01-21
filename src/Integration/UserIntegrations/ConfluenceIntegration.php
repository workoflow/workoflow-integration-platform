<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\AtlassianOAuthService;
use App\Service\Integration\ConfluenceService;
use Psr\Log\LoggerInterface;
use Twig\Environment;

class ConfluenceIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private ConfluenceService $confluenceService,
        private Environment $twig,
        private AtlassianOAuthService $atlassianOAuthService,
        private LoggerInterface $logger,
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
                'Search for Confluence pages using CQL (Confluence Query Language). Returns: Array of pages with results[].id, results[].title, results[].status, results[].spaceId, results[].parentId, results[].version.number, results[].body.storage, results[]._links.webui, results[]._links.editui, _links.next for pagination',
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
                'Get detailed content of a specific Confluence page. Returns: Page object with id, status, title, spaceId, parentId, parentType, authorId, createdAt, version.number, version.message, body.storage.value, body.atlas_doc_format, _links.webui, _links.editui',
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
                'Get comments from a specific Confluence page. Returns: Array of comments with results[].id, results[].status, results[].title, results[].version.number, results[].version.authorId, results[].version.createdAt, results[].body.storage, results[].body.atlas_doc_format, results[]._links.webui, _links.next for pagination',
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
                'Create a new page in Confluence. Supports markdown, plain text, or HTML content formats for AI-friendly page creation. Returns: Created page object with id, status, title, spaceId, parentId, authorId, createdAt, version.number, body.storage.value, _links.webui, _links.editui',
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
            ),
            new ToolDefinition(
                'confluence_update_page',
                'Update an existing Confluence page. Automatically handles version conflicts and supports multiple content formats for AI-friendly page updates. Returns: Updated page object with id, status, title, spaceId, parentId, version.number (incremented), version.message, version.authorId, body.storage.value, _links.webui, _links.editui',
                [
                    [
                        'name' => 'pageId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Page ID to update. Use confluence_search or confluence_get_page to find the page ID.'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New page title. Leave empty to keep the current title. Example: \'Updated Meeting Notes - Q4 Planning\''
                    ],
                    [
                        'name' => 'content',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'New page content. Supports markdown (# Headers, **bold**, *italic*, [links](url), etc.), plain text, or HTML. Example: \'# Updated Content\\n\\n## Changes\\n* Item 1 completed\\n* Item 2 in progress\''
                    ],
                    [
                        'name' => 'contentFormat',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Format of the content: \'markdown\' (default), \'plain\', \'html\', or \'storage\' (Confluence native format). Most AI agents should use \'markdown\' for best results.'
                    ],
                    [
                        'name' => 'versionMessage',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional message describing what changed in this update. Example: \'Added Q4 action items and updated attendee list\''
                    ],
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Page status: \'current\' (published) or \'draft\'. Leave empty to keep the current status.'
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
            'confluence_update_page' => $this->confluenceService->updatePage(
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

    /**
     * Enrich OAuth credentials with refreshed tokens if needed.
     *
     * @param array $credentials Current credentials from database
     *
     * @return array Enriched credentials with valid access token
     *
     * @throws \RuntimeException If token refresh fails and re-authentication is required
     */
    public function getOAuth2EnrichedCredentials(array $credentials): array
    {
        $authMode = $credentials['auth_mode'] ?? 'api_token';

        // Only enrich OAuth credentials
        if ($authMode !== 'oauth') {
            return $credentials;
        }

        try {
            return $this->atlassianOAuthService->ensureValidToken($credentials);
        } catch (\RuntimeException $e) {
            $this->logger->error('Confluence OAuth: Token refresh failed, re-authentication required', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function validateCredentials(array $credentials): bool
    {
        $authMode = $credentials['auth_mode'] ?? 'api_token';

        if ($authMode === 'oauth') {
            // For OAuth, check if we have valid tokens and cloud_id
            if (empty($credentials['access_token']) || empty($credentials['cloud_id'])) {
                return false;
            }

            // Try to ensure token is valid (will refresh if needed)
            try {
                $enrichedCredentials = $this->getOAuth2EnrichedCredentials($credentials);
                return $this->confluenceService->testConnection($enrichedCredentials);
            } catch (\Exception) {
                return false;
            }
        }

        // API Token mode: check if all required fields are present and not empty
        if (empty($credentials['url']) || empty($credentials['username']) || empty($credentials['api_token'])) {
            return false;
        }

        // Make actual API call to validate credentials
        try {
            return $this->confluenceService->testConnection($credentials);
        } catch (\Exception) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            // Auth mode selector - defaults to api_token for backward compatibility
            new CredentialField(
                'auth_mode',
                'select',
                'Authentication Mode',
                'api_token',
                true,
                'Choose how to authenticate with Confluence. OAuth 2.0 is recommended for better security and automatic token refresh.',
                [
                    'api_token' => 'API Token (Personal)',
                    'oauth' => 'OAuth 2.0 (Recommended)',
                ]
            ),

            // === API Token mode fields (shown when auth_mode = api_token) ===
            new CredentialField(
                'url',
                'url',
                'Confluence URL',
                'https://your-domain.atlassian.net',
                false, // Not globally required, only required for api_token mode
                'Your Confluence instance URL (e.g., https://your-domain.atlassian.net) - do not include /wiki',
                null,
                'auth_mode',
                'api_token'
            ),
            new CredentialField(
                'username',
                'email',
                'Email',
                'your-email@example.com',
                false,
                'Email address associated with your Confluence account',
                null,
                'auth_mode',
                'api_token'
            ),
            new CredentialField(
                'api_token',
                'password',
                'API Token',
                null,
                false,
                'Your Confluence API token (not your password). <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" rel="noopener">Create API token</a>',
                null,
                'auth_mode',
                'api_token'
            ),

            // === OAuth mode fields (shown when auth_mode = oauth) ===
            new CredentialField(
                'oauth_atlassian',
                'oauth',
                'Connect with Atlassian',
                null,
                false,
                'Authorize via Atlassian to access Confluence. This grants secure access without sharing your password.',
                null,
                'auth_mode',
                'oauth'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/confluence_full.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }
    public function isExperimental(): bool
    {
        return false;
    }

    public function getSetupInstructions(): ?string
    {
        return <<<'HTML'
<div class="setup-instructions">
    <h4>Authentication Options</h4>
    <p>You can connect to Confluence using one of two methods:</p>

    <div class="auth-option">
        <h5>Option 1: OAuth 2.0 (Recommended)</h5>
        <p>The most secure option with automatic token refresh:</p>
        <ol>
            <li>Select "OAuth 2.0 (Recommended)" as the authentication mode</li>
            <li>Click "Connect with Atlassian"</li>
            <li>Sign in to your Atlassian account and authorize access</li>
            <li>You'll be redirected back automatically</li>
        </ol>
        <p class="text-muted small">OAuth tokens are automatically refreshed, so you won't need to re-authenticate frequently.</p>
    </div>

    <div class="auth-option mt-3">
        <h5>Option 2: API Token (Personal)</h5>
        <p>Use a personal API token for authentication:</p>
        <ol>
            <li>Select "API Token (Personal)" as the authentication mode</li>
            <li>Enter your Confluence URL (e.g., https://your-domain.atlassian.net)</li>
            <li>Enter the email address associated with your Atlassian account</li>
            <li>Create an API token at <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" rel="noopener">Atlassian Account Settings</a></li>
            <li>Enter the API token (not your password)</li>
        </ol>
    </div>
</div>
HTML;
    }
}
