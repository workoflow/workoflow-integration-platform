<?php

namespace App\Controller;

use App\DTO\ToolFilterCriteria;
use App\Entity\IntegrationConfig;
use App\Entity\Organisation;
use App\Integration\IntegrationRegistry;
use App\Repository\IntegrationConfigRepository;
use App\Repository\OrganisationRepository;
use App\Service\EncryptionService;
use App\Service\IntegrationAuditService;
use App\Service\ToolProviderService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/integrations')]
class IntegrationApiController extends AbstractController
{
    public function __construct(
        private OrganisationRepository $organisationRepository,
        private IntegrationConfigRepository $integrationConfigRepository,
        private EncryptionService $encryptionService,
        private IntegrationRegistry $integrationRegistry,
        private ToolProviderService $toolProviderService,
        #[Autowire(service: 'monolog.logger.integration_api')]
        private LoggerInterface $logger,
        private string $apiAuthUser,
        private string $apiAuthPassword
    ) {
    }

    #[Route('/{organisationUuid}', name: 'api_integration_tools', methods: ['GET'])]
    public function getTools(string $organisationUuid, Request $request): JsonResponse
    {
        // Validate Basic Auth
        if (!$this->validateBasicAuth($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Get organisation
        $organisation = $this->organisationRepository->findOneBy(['uuid' => $organisationUuid]);
        if (!$organisation) {
            return $this->json(['error' => 'Organisation not found'], Response::HTTP_NOT_FOUND);
        }

        // Parse filter criteria (supports CSV: ?tool_type=system,jira,confluence)
        $criteria = ToolFilterCriteria::fromCsv(
            $request->query->get('workflow_user_id'),
            $request->query->get('tool_type')
        );

        // Delegate to service
        $tools = $this->toolProviderService->getToolsForOrganisation($organisation, $criteria);

        return $this->json(['tools' => $tools]);
    }

    #[Route('/{organisationUuid}/execute', name: 'api_integration_execute', methods: ['POST'])]
    public function executeTool(string $organisationUuid, Request $request): JsonResponse
    {
        // Validate Basic Auth
        if (!$this->validateBasicAuth($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Get organisation
        $organisation = $this->organisationRepository->findOneBy(['uuid' => $organisationUuid]);
        if (!$organisation) {
            return $this->json(['error' => 'Organisation not found'], Response::HTTP_NOT_FOUND);
        }

        // Get request data
        $data = json_decode($request->getContent(), true);
        $toolId = $data['tool_id'] ?? null;
        $parameters = $data['parameters'] ?? [];
        $workflowUserId = $data['workflow_user_id'] ?? null;

        if (!$toolId) {
            return $this->json(['error' => 'Tool ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('API Tool execution requested', [
            'organisation' => $organisation->getName(),
            'tool_id' => $toolId,
            'workflow_user_id' => $workflowUserId
        ]);

        // Parse tool ID (might have _configId suffix for user integrations)
        $parts = explode('_', $toolId);
        $configId = null;
        $toolName = $toolId;

        // Check if this is a user integration tool with config ID
        if (count($parts) > 1 && is_numeric(end($parts))) {
            $configId = (int) array_pop($parts);
            $toolName = implode('_', $parts);
        }

        try {
            // Find the integration that handles this tool
            $targetIntegration = null;
            $targetTool = null;

            foreach ($this->integrationRegistry->getAllIntegrations() as $integration) {
                foreach ($integration->getTools() as $tool) {
                    if ($tool->getName() === $toolName) {
                        $targetIntegration = $integration;
                        $targetTool = $tool;
                        break 2;
                    }
                }
            }

            if (!$targetIntegration || !$targetTool) {
                return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
            }

            // Get credentials if needed
            $credentials = null;
            if ($targetIntegration->requiresCredentials()) {
                if (!$configId) {
                    return $this->json(['error' => 'Configuration ID required for user integration tools'], Response::HTTP_BAD_REQUEST);
                }

                $config = $this->integrationConfigRepository->find($configId);
                if (!$config || $config->getOrganisation() !== $organisation) {
                    return $this->json(['error' => 'Configuration not found'], Response::HTTP_NOT_FOUND);
                }

                if (!$config->isActive() || $config->isToolDisabled($toolName)) {
                    return $this->json(['error' => 'Tool is disabled'], Response::HTTP_FORBIDDEN);
                }

                // Decrypt credentials
                $encryptedCreds = $config->getEncryptedCredentials();
                if ($encryptedCreds) {
                    $credentials = json_decode($this->encryptionService->decrypt($encryptedCreds), true);
                }

                // Update last accessed
                $this->integrationConfigRepository->updateLastAccessed($config);
            } else {
                // For system tools, check if disabled (if config exists)
                $config = $this->integrationConfigRepository->findOneByOrganisationAndType(
                    $organisation,
                    $targetIntegration->getType(),
                    $workflowUserId
                );

                if ($config && (!$config->isActive() || $config->isToolDisabled($toolName))) {
                    return $this->json(['error' => 'Tool is disabled'], Response::HTTP_FORBIDDEN);
                }
            }

            // Add organisation context to parameters
            $parameters['organisationId'] = $organisation->getId();
            $parameters['organisationUuid'] = $organisation->getUuid();
            $parameters['workflowUserId'] = $workflowUserId;

            // Execute the tool
            $result = $targetIntegration->executeTool($toolName, $parameters, $credentials);

            $this->logger->info('API Tool executed successfully', [
                'organisation' => $organisation->getName(),
                'tool_id' => $toolId,
                'integration_type' => $targetIntegration->getType()
            ]);

            return $this->json([
                'success' => true,
                'result' => $result
            ]);
        } catch (\Exception $e) {
            $this->logger->error('API Tool execution failed', [
                'organisation' => $organisation->getName(),
                'tool_id' => $toolId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Tool execution failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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

        return $username === $this->apiAuthUser && $password === $this->apiAuthPassword;
    }
}
