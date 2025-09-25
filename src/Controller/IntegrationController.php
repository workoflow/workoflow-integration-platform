<?php

namespace App\Controller;

use App\Entity\IntegrationConfig;
use App\Entity\User;
use App\Integration\IntegrationRegistry;
use App\Repository\IntegrationConfigRepository;
use App\Service\AuditLogService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/integrations')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class IntegrationController extends AbstractController
{
    public function __construct(
        private IntegrationConfigRepository $integrationConfigRepository,
        private IntegrationRegistry $integrationRegistry,
        private EncryptionService $encryptionService,
        private AuditLogService $auditLogService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/', name: 'app_integrations')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        // Get all integrations from registry (code-defined)
        $allIntegrations = $this->integrationRegistry->getAllIntegrations();

        // Get user configs from database
        $configs = $this->integrationConfigRepository->findByOrganisation($organisation);

        // Build config map for quick lookup (type => array of configs)
        $configMap = [];
        foreach ($configs as $config) {
            $type = $config->getIntegrationType();
            if (!isset($configMap[$type])) {
                $configMap[$type] = [];
            }
            $configMap[$type][] = $config;
        }

        // Build display data
        $displayData = [];
        foreach ($allIntegrations as $integration) {
            $type = $integration->getType();
            $integrationConfigs = $configMap[$type] ?? [];

            // For system integrations, always show one entry
            if (!$integration->requiresCredentials()) {
                $config = $integrationConfigs[0] ?? null;

                // Build tools display with enabled/disabled status
                $tools = [];
                foreach ($integration->getTools() as $tool) {
                    $tools[] = [
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'enabled' => !($config && $config->isToolDisabled($tool->getName()))
                    ];
                }

                $displayData[] = [
                    'type' => $integration->getType(),
                    'name' => $integration->getName(),
                    'instanceName' => null,
                    'instanceId' => $config?->getId(),
                    'isSystem' => true,
                    'config' => $config,
                    'tools' => $tools,
                    'hasCredentials' => false,
                    'active' => $config ? $config->isActive() : true,
                    'lastAccessedAt' => $config?->getLastAccessedAt()
                ];
            } else {
                // For user integrations, show each instance
                if (empty($integrationConfigs)) {
                    // Show placeholder for adding new instance
                    $displayData[] = [
                        'type' => $integration->getType(),
                        'name' => $integration->getName(),
                        'instanceName' => null,
                        'instanceId' => null,
                        'isSystem' => false,
                        'config' => null,
                        'tools' => [],
                        'hasCredentials' => false,
                        'active' => false,
                        'lastAccessedAt' => null
                    ];
                } else {
                    // Show each configured instance
                    foreach ($integrationConfigs as $config) {
                        // Build tools display with enabled/disabled status
                        $tools = [];
                        foreach ($integration->getTools() as $tool) {
                            $tools[] = [
                                'name' => $tool->getName(),
                                'description' => $tool->getDescription(),
                                'enabled' => !$config->isToolDisabled($tool->getName())
                            ];
                        }

                        $displayData[] = [
                            'type' => $integration->getType(),
                            'name' => $integration->getName(),
                            'instanceName' => $config->getName(),
                            'instanceId' => $config->getId(),
                            'isSystem' => false,
                            'config' => $config,
                            'tools' => $tools,
                            'hasCredentials' => $config->hasCredentials(),
                            'active' => $config->isActive(),
                            'lastAccessedAt' => $config->getLastAccessedAt()
                        ];
                    }
                }
            }
        }

        // Sort: system tools first, then user integrations
        usort($displayData, function ($a, $b) {
            if ($a['isSystem'] !== $b['isSystem']) {
                return $a['isSystem'] ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });

        // Get available integration types for the dropdown
        $availableTypes = [];
        foreach ($this->integrationRegistry->getUserIntegrations() as $integration) {
            $availableTypes[] = [
                'type' => $integration->getType(),
                'name' => $integration->getName()
            ];
        }

        return $this->render('integration/index.html.twig', [
            'integrations' => $displayData,
            'organisation' => $organisation,
            'availableTypes' => $availableTypes
        ]);
    }

    #[Route('/setup/{type}', name: 'app_integration_setup', methods: ['GET', 'POST'])]
    public function setup(string $type, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        // Find integration in registry
        $integration = $this->integrationRegistry->get($type);
        if (!$integration) {
            $this->addFlash('error', 'Integration type not found');
            return $this->redirectToRoute('app_integrations');
        }

        // System tools don't need setup
        if (!$integration->requiresCredentials()) {
            $this->addFlash('info', 'This integration does not require setup');
            return $this->redirectToRoute('app_integrations');
        }

        // Check if editing existing or creating new
        $instanceId = $request->query->get('instance');
        $config = null;

        if ($instanceId) {
            $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($instanceId);
            if (!$config || $config->getOrganisation() !== $organisation) {
                $this->addFlash('error', 'Instance not found');
                return $this->redirectToRoute('app_integrations');
            }
        }

        // Get existing instances for this type
        $existingInstances = $this->integrationConfigRepository->findByOrganisationAndType($organisation, $type);

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name', '');
            // Get workflow_user_id from the user's organization relationship
            $userOrg = null;
            foreach ($user->getUserOrganisations() as $userOrganisation) {
                if ($userOrganisation->getOrganisation() === $organisation) {
                    $userOrg = $userOrganisation;
                    break;
                }
            }
            $workflowUserId = $userOrg ? $userOrg->getWorkflowUserId() : null;

            // Validate name is provided and unique
            if (empty($name)) {
                $this->addFlash('error', 'Please provide a name for this integration instance');
            } else {
                // Check for duplicate names
                $isDuplicate = false;
                foreach ($existingInstances as $existing) {
                    if ($existing->getName() === $name && (!$config || $existing->getId() !== $config->getId())) {
                        $isDuplicate = true;
                        break;
                    }
                }

                if ($isDuplicate) {
                    $this->addFlash('error', 'An integration with this name already exists');
                } else {
                    // Get credentials from form
                    $credentials = [];
                    $hasOAuthField = false;
                    foreach ($integration->getCredentialFields() as $field) {
                        if ($field->getType() === 'oauth') {
                            $hasOAuthField = true;
                            continue; // Skip OAuth fields, they'll be handled separately
                        }
                        $value = $request->request->get($field->getName());
                        if ($value) {
                            $credentials[$field->getName()] = $value;
                        }
                    }

                    // For OAuth integrations, start OAuth flow immediately
                    if ($hasOAuthField && !$config) {
                        // Create temporary config to get ID for OAuth flow
                        $tempConfig = new IntegrationConfig();
                        $tempConfig->setOrganisation($organisation);
                        $tempConfig->setIntegrationType($type);
                        $tempConfig->setUser($user);
                        $tempConfig->setName($name);
                        $tempConfig->setActive(false); // Not active until OAuth completes

                        $this->entityManager->persist($tempConfig);
                        $this->entityManager->flush();

                        // Store in session that we're in OAuth flow
                        $request->getSession()->set('oauth_flow_integration', $tempConfig->getId());

                        // Redirect to OAuth authorization
                        return $this->redirectToRoute('app_integration_oauth_microsoft_start', [
                            'configId' => $tempConfig->getId()
                        ]);
                    }

                    // For non-OAuth integrations or editing existing OAuth configs
                    $shouldSave = false;
                    if ($hasOAuthField && $config && $config->hasCredentials()) {
                        // Existing OAuth config with credentials can be updated
                        $shouldSave = true;
                    } elseif (!$hasOAuthField && !empty($credentials) && $integration->validateCredentials($credentials)) {
                        // Non-OAuth with valid credentials
                        $shouldSave = true;
                    }

                    if ($shouldSave) {
                        // Create or update config
                        if (!$config) {
                            $config = new IntegrationConfig();
                            $config->setOrganisation($organisation);
                            $config->setIntegrationType($type);
                            $config->setUser($user);
                        }

                        $config->setName($name);
                        $config->setEncryptedCredentials(
                            $this->encryptionService->encrypt(json_encode($credentials))
                        );
                        $config->setActive(true);

                        // Auto-disable less useful tools for SharePoint
                        if ($type === 'sharepoint' && !$instanceId) {
                            // These tools are less useful for AI agents, disable by default
                            $toolsToDisable = [
                                'sharepoint_list_files',      // Agents should search, not browse
                                'sharepoint_download_file',    // Only returns URL, agent can't fetch it
                                'sharepoint_get_list_items'    // Too technical for most use cases
                            ];

                            foreach ($toolsToDisable as $toolName) {
                                $config->disableTool($toolName);
                            }

                            error_log('Auto-disabled less useful SharePoint tools for new integration: ' . implode(', ', $toolsToDisable));
                        }

                        $this->entityManager->persist($config);
                        $this->entityManager->flush();

                        /** @var User $logUser */
                        $logUser = $this->getUser();
                        $this->auditLogService->log(
                            $instanceId ? 'integration.updated' : 'integration.setup',
                            $logUser,
                            ['type' => $type, 'name' => $name]
                        );

                        $this->addFlash('success', 'Integration "' . $name . '" configured successfully');
                        return $this->redirectToRoute('app_integrations');
                    } else {
                        $this->addFlash('error', 'Invalid credentials provided');
                    }
                }
            }
        }

        return $this->render('integration/setup.html.twig', [
            'integration' => $integration,
            'config' => $config,
            'organisation' => $organisation,
            'credentialFields' => $integration->getCredentialFields(),
            'existingInstances' => $existingInstances,
            'isEdit' => $config !== null
        ]);
    }

    #[Route('/{type}/toggle-tool', name: 'app_integration_toggle_tool', methods: ['POST'])]
    public function toggleTool(string $type, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->json(['error' => 'No organisation'], Response::HTTP_FORBIDDEN);
        }

        $toolName = $request->request->get('tool');
        $enabled = $request->request->get('enabled') === 'true';
        $instanceId = $request->request->get('instance');

        // Validate instanceId is provided and valid
        if (!$instanceId || $instanceId === 'null' || empty($instanceId)) {
            return $this->json(['error' => 'Instance ID is required'], Response::HTTP_BAD_REQUEST);
        }

        // Get specific instance
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($instanceId);
        if (!$config || $config->getOrganisation() !== $organisation) {
            return $this->json(['error' => 'Instance not found'], Response::HTTP_NOT_FOUND);
        }

        // Update tool state
        if ($enabled) {
            $config->enableTool($toolName);
        } else {
            $config->disableTool($toolName);
        }

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        /** @var User $logUser */
        $logUser = $this->getUser();
        $this->auditLogService->log(
            $enabled ? 'integration.tool.enabled' : 'integration.tool.disabled',
            $logUser,
            ['type' => $type, 'tool' => $toolName, 'instance' => $config->getName()]
        );

        return $this->json(['success' => true]);
    }

    #[Route('/{type}/toggle', name: 'app_integration_toggle', methods: ['POST'])]
    public function toggleIntegration(string $type, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->json(['error' => 'No organisation'], Response::HTTP_FORBIDDEN);
        }

        $active = $request->request->get('active') === 'true';
        $instanceId = $request->request->get('instance');

        // If instance ID is provided, find the specific integration instance
        if ($instanceId) {
            $config = $this->integrationConfigRepository->findOneBy([
                'id' => $instanceId,
                'organisation' => $organisation,
                'integrationType' => $type
            ]);

            if (!$config) {
                return $this->json(['error' => 'Integration instance not found'], Response::HTTP_NOT_FOUND);
            }
        } else {
            // Fallback to the old behavior for backward compatibility
            $config = $this->integrationConfigRepository->getOrCreate($organisation, $type);
        }

        $config->setActive($active);

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        /** @var User $logUser */
        $logUser = $this->getUser();
        $this->auditLogService->log(
            $active ? 'integration.activated' : 'integration.deactivated',
            $logUser,
            [
                'type' => $type,
                'instance' => $config->getId(),
                'name' => $config->getName()
            ]
        );

        return $this->json(['success' => true]);
    }


    #[Route('/delete/{instanceId}', name: 'app_integration_delete', methods: ['POST'])]
    public function delete(int $instanceId, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($instanceId);

        if ($config && $config->getOrganisation() === $organisation) {
            /** @var User $logUser */
            $logUser = $this->getUser();
            $this->auditLogService->log(
                'integration.deleted',
                $logUser,
                ['type' => $config->getIntegrationType(), 'name' => $config->getName()]
            );

            $this->entityManager->remove($config);
            $this->entityManager->flush();

            $this->addFlash('success', 'Integration removed successfully');
        } else {
            $this->addFlash('error', 'Integration not found');
        }

        return $this->redirectToRoute('app_integrations');
    }

    #[Route('/{type}/test', name: 'app_integration_test', methods: ['POST'])]
    public function testConnection(string $type, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->json(['success' => false, 'message' => 'No organisation'], Response::HTTP_FORBIDDEN);
        }

        // Find integration in registry
        $integration = $this->integrationRegistry->get($type);
        if (!$integration) {
            return $this->json(['success' => false, 'message' => 'Integration type not found']);
        }

        // System tools don't need connection test
        if (!$integration->requiresCredentials()) {
            return $this->json(['success' => true, 'message' => 'System integration is always available']);
        }

        // Get config
        $config = $this->integrationConfigRepository->findOneByOrganisationAndType($organisation, $type);

        if (!$config || !$config->hasCredentials()) {
            return $this->json(['success' => false, 'message' => 'No credentials configured']);
        }

        try {
            // Decrypt credentials
            $credentials = json_decode(
                $this->encryptionService->decrypt($config->getEncryptedCredentials()),
                true
            );

            // Validate credentials
            if ($integration->validateCredentials($credentials)) {
                return $this->json(['success' => true, 'message' => 'Connection successful']);
            } else {
                return $this->json(['success' => false, 'message' => 'Invalid credentials']);
            }
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Connection test failed: ' . $e->getMessage()]);
        }
    }
}
