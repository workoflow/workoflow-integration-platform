<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\JiraService;
use Twig\Environment;

class JiraIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private JiraService $jiraService,
        private Environment $twig
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
                'Search for Jira issues using JQL (Jira Query Language). Returns: Object with startAt, maxResults, total, and issues array. Each issue contains: id, self, key, fields (including summary, status.name, assignee.displayName, priority.name, project.key, description, created, updated)',
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
                'Get detailed information about a specific Jira issue. Returns: Issue object with id, key, self, and fields containing summary, description, status.name, assignee (accountId, displayName, emailAddress), priority.name, project (key, name), reporter, created, updated, comment array, attachment array, issuelinks, subtasks, and watchers',
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
                'jira_get_board',
                'Get board details including type (scrum/kanban), name, and project information. Use this to detect board type before using sprint-specific tools. Returns: Object with id, self, name, type (scrum/kanban), and location (containing projectId, projectKey, projectName, displayName, projectTypeKey)',
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
                'jira_get_sprints_from_board',
                'Get all sprints from a Scrum board (Scrum boards only - does NOT work with Kanban boards). Use jira_get_board first to verify board type. For Kanban boards, use jira_get_kanban_issues instead. Returns: Object with maxResults, startAt, total, isLast, and values array. Each sprint contains: id, self, state (future/active/closed), name, startDate, endDate, completeDate (for closed sprints), originBoardId',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Board ID (must be a Scrum board)'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_sprint_issues',
                'Get all issues in a specific sprint. Returns: Object with expand, startAt, maxResults, total, and issues array. Each issue contains: id, key, self, and fields including summary, status.name, assignee.displayName, priority.name, sprint (current sprint), closedSprints array, flagged, epic, description, project, timetracking',
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
                'Add a comment to a Jira issue. Returns: Comment object with self, id, author (accountId, displayName, emailAddress, active), body (document format), created, updated, updateAuthor, and visibility settings',
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
            ),
            new ToolDefinition(
                'jira_get_available_transitions',
                'Get available status transitions for a Jira issue. Use this to find out which status changes are possible for an issue based on the workflow. Returns: Object with expand and transitions array. Each transition contains: id, name, description, hasScreen, isAvailable, isConditional, isGlobal, isInitial, to (status object with id, name, description, iconUrl, statusCategory), and fields (required/optional fields for the transition)',
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
                'jira_transition_issue',
                'Change the status of a Jira issue by executing a workflow transition. Always call jira_get_available_transitions first to get valid transition IDs. Returns: HTTP 204 No Content on success (no response body), or error details if the transition fails',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ],
                    [
                        'name' => 'transitionId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Transition ID from jira_get_available_transitions'
                    ],
                    [
                        'name' => 'comment',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional comment to add when transitioning the issue'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_transition_issue_to_status',
                'Intelligently transition a Jira issue to a target status by automatically navigating through the workflow. Use this when the user asks to "set status to Done" or similar - the system will automatically find and execute the required workflow path. Returns: Success message with the new status name and transition details, or error message if the target status cannot be reached',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ],
                    [
                        'name' => 'targetStatusName',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Target status name (e.g., "Done", "In Progress", "Closed")'
                    ],
                    [
                        'name' => 'comment',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional comment to add during the transition'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_board_issues',
                'Universal tool to get issues from any board type (works with both Scrum and Kanban). Automatically detects board type and retrieves issues accordingly: for Scrum boards, returns issues from the active sprint; for Kanban boards, returns all issues on the board. Returns: Object with expand, startAt, maxResults, total, and issues array. Each issue contains: id, key, self, and fields including summary, status.name, assignee.displayName, priority.name, project, description',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Board ID (works with both Scrum and Kanban boards)'
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
                'jira_get_kanban_issues',
                'Get all issues from a Kanban board. This is specifically designed for Kanban boards which do not have sprints. Returns: Object with expand, startAt, maxResults, total, and issues array. Each issue contains: id, key, self, and fields including summary, status.name, assignee.displayName, priority.name, project, description, created, updated',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Kanban Board ID'
                    ],
                    [
                        'name' => 'maxResults',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50)'
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
            'jira_get_board' => $this->jiraService->getBoard(
                $credentials,
                $parameters['boardId']
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
            'jira_get_available_transitions' => $this->jiraService->getAvailableTransitions(
                $credentials,
                $parameters['issueKey']
            ),
            'jira_transition_issue' => $this->jiraService->transitionIssue(
                $credentials,
                $parameters['issueKey'],
                $parameters['transitionId'],
                $parameters['comment'] ?? null
            ),
            'jira_transition_issue_to_status' => $this->jiraService->transitionIssueToStatus(
                $credentials,
                $parameters['issueKey'],
                $parameters['targetStatusName'],
                $parameters['comment'] ?? null
            ),
            'jira_get_board_issues' => $this->jiraService->getBoardIssues(
                $credentials,
                $parameters['boardId'],
                $parameters['maxResults'] ?? 50
            ),
            'jira_get_kanban_issues' => $this->jiraService->getKanbanIssues(
                $credentials,
                $parameters['boardId'],
                $parameters['maxResults'] ?? 50
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

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/jira_full.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }
}
