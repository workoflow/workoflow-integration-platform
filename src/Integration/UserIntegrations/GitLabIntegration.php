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
                'Get detailed information about a GitLab project. Returns: Object with id, name, path, path_with_namespace, description, web_url, default_branch, visibility, created_at, namespace, owner, and other project metadata',
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
                'List GitLab projects accessible to the authenticated user. Returns: Array of projects with id, name, path, path_with_namespace, description, web_url, default_branch, visibility, created_at, last_activity_at, namespace',
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
                'Get the content of a file from the repository. Returns: Object with file_name, file_path, size, encoding, content (base64 or plain text), content_sha256, ref, blob_id, commit_id, last_commit_id',
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
                'List files and directories in the repository. Returns: Array of tree items with id, name, type (blob/tree), path, mode',
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
                'List all tags in the repository. Returns: Array of tags with name, message, target (commit SHA), commit (id, short_id, title, author_name, created_at), release, protected',
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
                'List all branches in the repository. Returns: Array of branches with name, commit (id, short_id, title, author_name, created_at), merged, protected, default, can_push, web_url',
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
                'Get detailed information about a specific commit. Returns: Object with id, short_id, title, message, author_name, author_email, authored_date, committer_name, committer_email, committed_date, created_at, parent_ids, web_url, stats (additions, deletions, total)',
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
                'List commits in the repository. Returns: Array of commits with id, short_id, title, message, author_name, author_email, authored_date, committer_name, created_at, parent_ids, web_url',
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
                'Search merge requests in a project. Returns: Array of MRs with id, iid, project_id, title, description, state, web_url, author, assignee, source_branch, target_branch, created_at, updated_at, merge_status',
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
                'Get detailed information about a merge request. Returns: Object with id, iid, project_id, title, description, state, web_url, author, assignee, assignees, reviewers, source_branch, target_branch, sha, merge_commit_sha, merge_status, created_at, updated_at, merged_at, closed_at, milestone, labels, pipeline, user_notes_count',
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
                'Get changes (diff) for a merge request. Returns: MR object with changes array containing old_path, new_path, a_mode, b_mode, diff, new_file, renamed_file, deleted_file for each changed file',
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
                'Get all discussions (comments) for a merge request. Returns: Array of discussions with id, individual_note (boolean), notes array (each note has id, body, author, created_at, updated_at, resolved, resolvable)',
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
                'Get all commits in a merge request. Returns: Array of commits with id, short_id, title, message, author_name, author_email, authored_date, committer_name, committer_email, committed_date, created_at, web_url',
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
                'Create a new merge request. Returns: MR object with id, iid, project_id, title, description, state, web_url, author, source_branch, target_branch, created_at, updated_at',
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
                'Update an existing merge request. Returns: Updated MR object with id, iid, title, description, state, web_url, updated_at',
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
                'Add a comment to a merge request. Returns: Note object with id, body, author (id, name, username, state, avatar_url, web_url), created_at, updated_at, system, noteable_id, noteable_type',
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
                'Search issues in a project. Returns: Array of issues with id, iid, project_id, title, description, state, web_url, author, assignee, assignees, labels, created_at, updated_at, closed_at, milestone, user_notes_count',
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
                'Get detailed information about an issue. Returns: Object with id, iid, project_id, title, description, state, web_url, author, assignee, assignees, labels, created_at, updated_at, closed_at, closed_by, milestone, user_notes_count, due_date, confidential, issue_type',
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
                'Create a new issue. Returns: Issue object with id, iid, project_id, title, description, state, web_url, author, created_at, updated_at',
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
                'Update an existing issue. Returns: Updated issue object with id, iid, title, description, state, web_url, updated_at',
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
                'Search packages in a project. Returns: Array of packages with id, name, version, package_type, created_at, pipelines (array with id, status, ref, sha, web_url, created_at, user)',
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
                'Get detailed information about a package. Returns: Object with id, name, version, package_type, created_at, project_id, pipelines, _links (web_path, delete_api_path)',
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
                'List CI/CD pipelines in a project. Returns: Array of pipelines with id, project_id, ref, sha, status, source, web_url, created_at, updated_at, started_at, finished_at, duration, user',
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
                'Get detailed information about a pipeline. Returns: Object with id, project_id, ref, sha, status, source, web_url, created_at, updated_at, started_at, finished_at, duration, coverage, user, detailed_status (icon, text, label, group)',
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
