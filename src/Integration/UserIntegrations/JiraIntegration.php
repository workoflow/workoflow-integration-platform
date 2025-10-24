<?php

namespace App\Integration\UserIntegrations;

use App\Integration\IntegrationInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\JiraService;

class JiraIntegration implements IntegrationInterface
{
    public function __construct(
        private JiraService $jiraService
    ) {
    }

    public function getType(): string
    {
        return 'jira';
    }

    public function getName(): string
    {
        return 'Jira';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'jira_search',
                'Search for Jira issues using JQL (Jira Query Language)',
                [
                    [
                        'name' => 'jql',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'JQL query string'
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
                'jira_get_issue',
                'Get detailed information about a specific Jira issue',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_sprints_from_board',
                'Get all sprints from a Jira board',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Board ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_sprint_issues',
                'Get all issues in a specific sprint',
                [
                    [
                        'name' => 'sprintId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Sprint ID'
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
                'jira_add_comment',
                'Add a comment to a Jira issue',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ],
                    [
                        'name' => 'comment',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The comment text to add to the issue'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('Jira integration requires credentials');
        }

        return match ($toolName) {
            'jira_search' => $this->jiraService->search(
                $credentials,
                $parameters['jql'],
                $parameters['maxResults'] ?? 50
            ),
            'jira_get_issue' => $this->jiraService->getIssue(
                $credentials,
                $parameters['issueKey']
            ),
            'jira_get_sprints_from_board' => $this->jiraService->getSprintsFromBoard(
                $credentials,
                $parameters['boardId']
            ),
            'jira_get_sprint_issues' => $this->jiraService->getSprintIssues(
                $credentials,
                $parameters['sprintId']
            ),
            'jira_add_comment' => $this->jiraService->addComment(
                $credentials,
                $parameters['issueKey'],
                $parameters['comment']
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
        // First check if all required fields are present and not empty
        if (empty($credentials['url']) || empty($credentials['username']) || empty($credentials['api_token'])) {
            return false;
        }

        // Use JiraService to validate credentials
        try {
            return $this->jiraService->testConnection($credentials);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'url',
                'url',
                'Jira URL',
                'https://your-domain.atlassian.net',
                true,
                'Your Jira instance URL'
            ),
            new CredentialField(
                'username',
                'email',
                'Email',
                'your-email@example.com',
                true,
                'Email address associated with your Jira account'
            ),
            new CredentialField(
                'api_token',
                'password',
                'API Token',
                null,
                true,
                'Your Jira API token (not your password). <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" rel="noopener">Create API token</a>'
            ),
        ];
    }
}
