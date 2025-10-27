<?php

namespace App\Integration\UserIntegrations;

use App\Integration\IntegrationInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\GitLabService;

class GitLabIntegration implements IntegrationInterface
{
    public function __construct(
        private GitLabService $gitLabService
    ) {
    }

    public function getType(): string
    {
        return 'gitlab';
    }

    public function getName(): string
    {
        return 'GitLab';
    }

    public function getTools(): array
    {
        return [
            // Repository & Metadata
            new ToolDefinition(
                'gitlab_get_project',
                'Get detailed information about a GitLab project',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path (e.g., "42" or "namespace/project")'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_projects',
                'List GitLab projects accessible to the authenticated user',
                [
                    [
                        'name' => 'membership',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Limit to projects the user is a member of (default: all accessible projects)'
                    ],
                    [
                        'name' => 'per_page',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results per page (default: 20)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_file_content',
                'Get the content of a file from the repository',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'file_path',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Path to the file in the repository'
                    ],
                    [
                        'name' => 'ref',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Branch, tag, or commit SHA (default: repository default branch)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_repository_tree',
                'List files and directories in the repository',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'path',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Path inside repository (default: root)'
                    ],
                    [
                        'name' => 'ref',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Branch, tag, or commit SHA (default: repository default branch)'
                    ],
                    [
                        'name' => 'recursive',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Get tree recursively (default: false)'
                    ]
                ]
            ),

            // Tags, Branches & Commits
            new ToolDefinition(
                'gitlab_list_tags',
                'List all tags in the repository',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_branches',
                'List all branches in the repository',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_commit',
                'Get detailed information about a specific commit',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'sha',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Commit SHA'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_commits',
                'List commits in the repository',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'ref_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Branch, tag, or commit SHA (default: repository default branch)'
                    ],
                    [
                        'name' => 'per_page',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results per page (default: 20)'
                    ]
                ]
            ),

            // Merge Requests (Read)
            new ToolDefinition(
                'gitlab_search_merge_requests',
                'Search merge requests in a project',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'state',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by state: opened, closed, merged, or all (default: all)'
                    ],
                    [
                        'name' => 'scope',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by scope: created_by_me, assigned_to_me, or all (default: all)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_merge_request',
                'Get detailed information about a merge request',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_merge_request_changes',
                'Get changes (diff) for a merge request',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_merge_request_discussions',
                'Get all discussions (comments) for a merge request',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_merge_request_commits',
                'Get all commits in a merge request',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),

            // Merge Requests (Write)
            new ToolDefinition(
                'gitlab_create_merge_request',
                'Create a new merge request',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'source_branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Source branch name'
                    ],
                    [
                        'name' => 'target_branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Target branch name'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Merge request title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Merge request description'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_update_merge_request',
                'Update an existing merge request',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New description'
                    ],
                    [
                        'name' => 'state_event',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'State event: close or reopen'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_add_merge_request_note',
                'Add a comment to a merge request',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ],
                    [
                        'name' => 'body',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Comment text'
                    ]
                ]
            ),

            // Issues
            new ToolDefinition(
                'gitlab_search_issues',
                'Search issues in a project',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'state',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by state: opened, closed, or all (default: all)'
                    ],
                    [
                        'name' => 'scope',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by scope: created_by_me, assigned_to_me, or all (default: all)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_issue',
                'Get detailed information about an issue',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'issue_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Issue internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_create_issue',
                'Create a new issue',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Issue description'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_update_issue',
                'Update an existing issue',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'issue_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Issue internal ID'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New description'
                    ],
                    [
                        'name' => 'state_event',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'State event: close or reopen'
                    ]
                ]
            ),

            // Packages
            new ToolDefinition(
                'gitlab_search_packages',
                'Search packages in a project',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'package_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by package name'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_package',
                'Get detailed information about a package',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'package_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Package ID'
                    ]
                ]
            ),

            // CI/CD
            new ToolDefinition(
                'gitlab_list_pipelines',
                'List CI/CD pipelines in a project',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'ref',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by branch or tag name'
                    ],
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by status: running, pending, success, failed, canceled, skipped, created, manual'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_pipeline',
                'Get detailed information about a pipeline',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'pipeline_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Pipeline ID'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('GitLab integration requires credentials');
        }

        return match ($toolName) {
            // Repository & Metadata
            'gitlab_get_project' => $this->gitLabService->getProject(
                $credentials,
                $parameters['project']
            ),
            'gitlab_list_projects' => $this->gitLabService->listProjects(
                $credentials,
                $parameters['membership'] ?? null,
                $parameters['per_page'] ?? 20
            ),
            'gitlab_get_file_content' => $this->gitLabService->getFileContent(
                $credentials,
                $parameters['project'],
                $parameters['file_path'],
                $parameters['ref'] ?? null
            ),
            'gitlab_list_repository_tree' => $this->gitLabService->listRepositoryTree(
                $credentials,
                $parameters['project'],
                $parameters['path'] ?? null,
                $parameters['ref'] ?? null,
                $parameters['recursive'] ?? false
            ),

            // Tags, Branches & Commits
            'gitlab_list_tags' => $this->gitLabService->listTags(
                $credentials,
                $parameters['project']
            ),
            'gitlab_list_branches' => $this->gitLabService->listBranches(
                $credentials,
                $parameters['project']
            ),
            'gitlab_get_commit' => $this->gitLabService->getCommit(
                $credentials,
                $parameters['project'],
                $parameters['sha']
            ),
            'gitlab_list_commits' => $this->gitLabService->listCommits(
                $credentials,
                $parameters['project'],
                $parameters['ref_name'] ?? null,
                $parameters['per_page'] ?? 20
            ),

            // Merge Requests (Read)
            'gitlab_search_merge_requests' => $this->gitLabService->searchMergeRequests(
                $credentials,
                $parameters['project'],
                $parameters['state'] ?? null,
                $parameters['scope'] ?? null
            ),
            'gitlab_get_merge_request' => $this->gitLabService->getMergeRequest(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_get_merge_request_changes' => $this->gitLabService->getMergeRequestChanges(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_get_merge_request_discussions' => $this->gitLabService->getMergeRequestDiscussions(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_get_merge_request_commits' => $this->gitLabService->getMergeRequestCommits(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),

            // Merge Requests (Write)
            'gitlab_create_merge_request' => $this->gitLabService->createMergeRequest(
                $credentials,
                $parameters['project'],
                $parameters['source_branch'],
                $parameters['target_branch'],
                $parameters['title'],
                $parameters['description'] ?? null
            ),
            'gitlab_update_merge_request' => $this->gitLabService->updateMergeRequest(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid'],
                $parameters['title'] ?? null,
                $parameters['description'] ?? null,
                $parameters['state_event'] ?? null
            ),
            'gitlab_add_merge_request_note' => $this->gitLabService->addMergeRequestNote(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid'],
                $parameters['body']
            ),

            // Issues
            'gitlab_search_issues' => $this->gitLabService->searchIssues(
                $credentials,
                $parameters['project'],
                $parameters['state'] ?? null,
                $parameters['scope'] ?? null
            ),
            'gitlab_get_issue' => $this->gitLabService->getIssue(
                $credentials,
                $parameters['project'],
                $parameters['issue_iid']
            ),
            'gitlab_create_issue' => $this->gitLabService->createIssue(
                $credentials,
                $parameters['project'],
                $parameters['title'],
                $parameters['description'] ?? null
            ),
            'gitlab_update_issue' => $this->gitLabService->updateIssue(
                $credentials,
                $parameters['project'],
                $parameters['issue_iid'],
                $parameters['title'] ?? null,
                $parameters['description'] ?? null,
                $parameters['state_event'] ?? null
            ),

            // Packages
            'gitlab_search_packages' => $this->gitLabService->searchPackages(
                $credentials,
                $parameters['project'],
                $parameters['package_name'] ?? null
            ),
            'gitlab_get_package' => $this->gitLabService->getPackage(
                $credentials,
                $parameters['project'],
                $parameters['package_id']
            ),

            // CI/CD
            'gitlab_list_pipelines' => $this->gitLabService->listPipelines(
                $credentials,
                $parameters['project'],
                $parameters['ref'] ?? null,
                $parameters['status'] ?? null
            ),
            'gitlab_get_pipeline' => $this->gitLabService->getPipeline(
                $credentials,
                $parameters['project'],
                $parameters['pipeline_id']
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
        if (empty($credentials['gitlab_url']) || empty($credentials['api_token'])) {
            return false;
        }

        // Use GitLabService to validate credentials
        try {
            return $this->gitLabService->testConnection($credentials);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'gitlab_url',
                'url',
                'GitLab URL',
                'https://gitlab.com',
                true,
                'Your GitLab instance URL (e.g., https://gitlab.com or https://gitlab.example.com)'
            ),
            new CredentialField(
                'api_token',
                'password',
                'API Token',
                null,
                true,
                'Your GitLab Personal Access Token with api, read_api, read_repository, and write_repository scopes. <a href="https://docs.gitlab.com/ee/user/profile/personal_access_tokens.html" target="_blank" rel="noopener">Create API token</a>'
            ),
        ];
    }
}
