<?php

namespace App\Controller;

use App\Repository\IntegrationRepository;
use App\Repository\OrganisationRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogService;
use App\Service\EncryptionService;
use App\Service\Integration\JiraService;
use App\Service\Integration\ConfluenceService;
use App\Service\ShareFileService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Route('/api/integration/{orgUuid}')]
class IntegrationApiController extends AbstractController
{
    public function __construct(
        private OrganisationRepository $organisationRepository,
        private UserRepository $userRepository,
        private IntegrationRepository $integrationRepository,
        private AuditLogService $auditLogService,
        private EncryptionService $encryptionService,
        private JiraService $jiraService,
        private ConfluenceService $confluenceService,
        private ShareFileService $shareFileService,
        #[Autowire(service: 'monolog.logger.integration_api')]
        private LoggerInterface $logger,
        private string $mcpAuthUser,
        private string $mcpAuthPassword
    ) {}

    #[Route('/tools', name: 'api_integration_tools', methods: ['GET'])]
    public function listTools(string $orgUuid, Request $request): JsonResponse
    {
        // Validate Basic Auth
        if (!$this->validateBasicAuth($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401, [
                'WWW-Authenticate' => 'Basic realm="Integration API"'
            ]);
        }

        // Validate organisation
        $organisation = $this->organisationRepository->findByUuid($orgUuid);
        if (!$organisation) {
            return new JsonResponse(['error' => 'Organisation not found'], 404);
        }

        // Get workflow user ID from query parameter
        $workflowUserId = $request->query->get('id');
        if (!$workflowUserId) {
            return new JsonResponse(['error' => 'Missing workflow user ID parameter'], 400);
        }

        $this->logger->info('API tools list request', [
            'org_uuid' => $orgUuid,
            'workflow_user_id' => $workflowUserId,
            'remote_addr' => $request->getClientIp()
        ]);

        // Find all integrations with this workflow user ID from users in this organisation
        $users = $this->userRepository->findByOrganisation($organisation->getId());
        $integrations = [];
        
        foreach ($users as $user) {
            $userIntegrations = $this->integrationRepository->findActiveByUserAndWorkflowId($user, $workflowUserId);
            $integrations = array_merge($integrations, $userIntegrations);
        }
        
        // Build tools array
        $tools = [];
        
        // Add static share_file tool
        $tools[] = [
            'id' => 'share_file',
            'name' => 'share_file',
            'description' => 'Upload and share a file. Returns a signed URL that can be used to access the file.',
            'integration_id' => null,
            'integration_name' => 'File Sharing',
            'integration_type' => 'system',
            'parameters' => $this->getParametersForFunction('share_file')
        ];
        
        foreach ($integrations as $integration) {
            if (!$integration->isActive()) {
                continue;
            }

            foreach ($integration->getActiveFunctions() as $function) {
                $tool = [
                    'id' => $function->getFunctionName() . '_' . $integration->getId(),
                    'name' => $function->getFunctionName(),
                    'description' => $function->getDescription(),
                    'integration_id' => $integration->getId(),
                    'integration_name' => $integration->getName(),
                    'integration_type' => $integration->getType(),
                    'parameters' => $this->getParametersForFunction($function->getFunctionName())
                ];
                
                $tools[] = $tool;
            }
        }

        return new JsonResponse([
            'tools' => $tools,
            'count' => count($tools)
        ]);
    }

    #[Route('/execute', name: 'api_integration_execute', methods: ['POST'])]
    public function executeTool(string $orgUuid, Request $request): JsonResponse
    {
        // Validate Basic Auth
        if (!$this->validateBasicAuth($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401, [
                'WWW-Authenticate' => 'Basic realm="Integration API"'
            ]);
        }

        // Validate organisation
        $organisation = $this->organisationRepository->findByUuid($orgUuid);
        if (!$organisation) {
            return new JsonResponse(['error' => 'Organisation not found'], 404);
        }

        // Get workflow user ID from query parameter
        $workflowUserId = $request->query->get('id');
        if (!$workflowUserId) {
            return new JsonResponse(['error' => 'Missing workflow user ID parameter'], 400);
        }

        // Parse request body
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        // Validate required fields
        if (!isset($data['tool_id']) || !isset($data['parameters'])) {
            return new JsonResponse(['error' => 'Missing required fields: tool_id, parameters'], 400);
        }

        $toolId = $data['tool_id'];
        $parameters = $data['parameters'];

        // Handle static share_file tool
        if ($toolId === 'share_file') {
            try {
                $result = $this->shareFileService->shareFile(
                    $parameters['binaryData'],
                    $parameters['contentType'],
                    $orgUuid
                );
                
                // Log the access
                $this->auditLogService->log(
                    'api.tool.execute',
                    null,
                    [
                        'tool_id' => $toolId,
                        'org_uuid' => $orgUuid,
                        'content_type' => $parameters['contentType']
                    ]
                );
                
                return new JsonResponse([
                    'success' => true,
                    'result' => $result
                ]);
            } catch (\Exception $e) {
                $this->logger->error('File sharing failed', [
                    'tool_id' => $toolId,
                    'error' => $e->getMessage()
                ]);
                
                return new JsonResponse([
                    'success' => false,
                    'error' => $e->getMessage()
                ], 500);
            }
        }

        // Extract integration ID from tool ID (format: functionName_integrationId)
        $parts = explode('_', $toolId);
        if (count($parts) < 2) {
            return new JsonResponse(['error' => 'Invalid tool_id format'], 400);
        }

        $integrationId = end($parts);
        $functionName = implode('_', array_slice($parts, 0, -1));

        $this->logger->info('API tool execution request', [
            'org_uuid' => $orgUuid,
            'tool_id' => $toolId,
            'function_name' => $functionName,
            'integration_id' => $integrationId,
            'remote_addr' => $request->getClientIp()
        ]);

        // Find integration
        $integration = $this->integrationRepository->find($integrationId);
        if (!$integration || !$integration->isActive()) {
            return new JsonResponse(['error' => 'Integration not found or inactive'], 404);
        }

        // Verify integration belongs to a user in this organisation and has the correct workflow ID
        $integrationUser = $integration->getUser();
        if (!$integrationUser || $integrationUser->getOrganisation()->getId() !== $organisation->getId()) {
            return new JsonResponse(['error' => 'Integration not found in this organisation'], 403);
        }
        
        // Verify workflow user ID matches
        if ($integration->getWorkflowUserId() !== $workflowUserId) {
            return new JsonResponse(['error' => 'Integration not found for this workflow user'], 403);
        }

        // Check if function is enabled
        $functionEnabled = false;
        foreach ($integration->getActiveFunctions() as $function) {
            if ($function->getFunctionName() === $functionName) {
                $functionEnabled = true;
                break;
            }
        }

        if (!$functionEnabled) {
            return new JsonResponse(['error' => 'Function not enabled for this integration'], 403);
        }

        // Update last accessed time
        $this->integrationRepository->updateLastAccessed($integration);

        // Log the access
        $this->auditLogService->log(
            'api.tool.execute',
            $integration->getUser(),
            [
                'tool_id' => $toolId,
                'integration_id' => $integrationId,
                'function_name' => $functionName
            ]
        );

        // Execute the tool
        try {
            $result = $this->executeToolFunction($integration, $functionName, $parameters);
            
            return new JsonResponse([
                'success' => true,
                'result' => $result
            ]);
        } catch (\Exception $e) {
            $this->logger->error('API tool execution failed', [
                'tool_id' => $toolId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function validateBasicAuth(Request $request): bool
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $encoded = substr($authHeader, 6);
        $decoded = base64_decode($encoded);
        
        if ($decoded === false) {
            return false;
        }

        [$username, $password] = explode(':', $decoded, 2);
        
        return $username === $this->mcpAuthUser && $password === $this->mcpAuthPassword;
    }

    private function getParametersForFunction(string $functionName): array
    {
        $parameters = [
            'jira_search' => [
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
                    'default' => 50,
                    'description' => 'Maximum number of results'
                ]
            ],
            'jira_get_issue' => [
                [
                    'name' => 'issueKey',
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Issue key (e.g. PROJECT-123)'
                ]
            ],
            'jira_get_sprints_from_board' => [
                [
                    'name' => 'boardId',
                    'type' => 'integer',
                    'required' => true,
                    'description' => 'Board ID'
                ]
            ],
            'jira_get_sprint_issues' => [
                [
                    'name' => 'sprintId',
                    'type' => 'integer',
                    'required' => true,
                    'description' => 'Sprint ID'
                ]
            ],
            'confluence_search' => [
                [
                    'name' => 'query',
                    'type' => 'string',
                    'required' => true,
                    'description' => 'CQL query string'
                ],
                [
                    'name' => 'limit',
                    'type' => 'integer',
                    'required' => false,
                    'default' => 25,
                    'description' => 'Maximum number of results'
                ]
            ],
            'confluence_get_page' => [
                [
                    'name' => 'pageId',
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Page ID'
                ]
            ],
            'confluence_get_comments' => [
                [
                    'name' => 'pageId',
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Page ID'
                ]
            ],
            'share_file' => [
                [
                    'name' => 'binaryData',
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Base64 encoded file content'
                ],
                [
                    'name' => 'contentType',
                    'type' => 'string',
                    'required' => true,
                    'description' => 'MIME type of the file (e.g. application/pdf, image/png)'
                ]
            ]
        ];
        
        return $parameters[$functionName] ?? [];
    }

    private function executeToolFunction($integration, string $functionName, array $parameters): array
    {
        $credentials = json_decode(
            $this->encryptionService->decrypt($integration->getEncryptedCredentials()),
            true
        );
        
        switch ($functionName) {
            case 'jira_search':
                return $this->jiraService->search($credentials, $parameters['jql'], $parameters['maxResults'] ?? 50);
                
            case 'jira_get_issue':
                return $this->jiraService->getIssue($credentials, $parameters['issueKey']);
                
            case 'jira_get_sprints_from_board':
                return $this->jiraService->getSprintsFromBoard($credentials, $parameters['boardId']);
                
            case 'jira_get_sprint_issues':
                return $this->jiraService->getSprintIssues($credentials, $parameters['sprintId']);
                
            case 'confluence_search':
                return $this->confluenceService->search($credentials, $parameters['query'], $parameters['limit'] ?? 25);
                
            case 'confluence_get_page':
                return $this->confluenceService->getPage($credentials, $parameters['pageId']);
                
            case 'confluence_get_comments':
                return $this->confluenceService->getComments($credentials, $parameters['pageId']);
                
            default:
                throw new \Exception('Unknown function: ' . $functionName);
        }
    }
}