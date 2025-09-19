<?php

namespace App\Controller;

use App\Entity\IntegrationConfig;
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
    ) {}

    #[Route('/', name: 'app_integrations')]
    public function index(Request $request): Response
    {
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
            // Get workflow_user_id from the user's organization
            $workflowUserId = $userOrganisation->getWorkflowUserId();

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
                    foreach ($integration->getCredentialFields() as $field) {
                        $value = $request->request->get($field->getName());
                        if ($value) {
                            $credentials[$field->getName()] = $value;
                        }
                    }

                    // Validate credentials
                    if (!empty($credentials) && $integration->validateCredentials($credentials)) {
                        // Create or update config
                        if (!$config) {
                            $config = new IntegrationConfig();
                            $config->setOrganisation($organisation);
                            $config->setIntegrationType($type);
                            $config->setUser($user);
                        }

                        $config->setName($name);
                        $config->setWorkflowUserId($workflowUserId);
                        $config->setEncryptedCredentials(
                            $this->encryptionService->encrypt(json_encode($credentials))
                        );
                        $config->setActive(true);

                        $this->entityManager->persist($config);
                        $this->entityManager->flush();

                        $this->auditLogService->log(
                            $instanceId ? 'integration.updated' : 'integration.setup',
                            $this->getUser(),
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
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->json(['error' => 'No organisation'], Response::HTTP_FORBIDDEN);
        }

        $toolName = $request->request->get('tool');
        $enabled = $request->request->get('enabled') === 'true';
        $instanceId = $request->request->get('instance');

        // Get specific instance or system config
        if ($instanceId) {
            $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($instanceId);
            if (!$config || $config->getOrganisation() !== $organisation) {
                return $this->json(['error' => 'Instance not found'], Response::HTTP_NOT_FOUND);
            }
        } else {
            // For system tools without instances
            $config = $this->integrationConfigRepository->getOrCreate($organisation, $type, null, ucfirst($type), $user);
        }

        // Update tool state
        if ($enabled) {
            $config->enableTool($toolName);
        } else {
            $config->disableTool($toolName);
        }

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        $this->auditLogService->log(
            $enabled ? 'integration.tool.enabled' : 'integration.tool.disabled',
            $this->getUser(),
            ['type' => $type, 'tool' => $toolName, 'instance' => $config->getName()]
        );

        return $this->json(['success' => true]);
    }

    #[Route('/{type}/toggle', name: 'app_integration_toggle', methods: ['POST'])]
    public function toggleIntegration(string $type, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->json(['error' => 'No organisation'], Response::HTTP_FORBIDDEN);
        }

        $active = $request->request->get('active') === 'true';

        // Get or create config
        $config = $this->integrationConfigRepository->getOrCreate($organisation, $type);
        $config->setActive($active);

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        $this->auditLogService->log(
            $active ? 'integration.activated' : 'integration.deactivated',
            $this->getUser(),
            ['type' => $type]
        );

        return $this->json(['success' => true]);
    }

    #[Route('/{type}/edit', name: 'app_integration_edit', methods: ['GET', 'POST'])]
    public function edit(string $type, Request $request): Response
    {
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        $config = $this->integrationConfigRepository->findOneByOrganisationAndType($organisation, $type);
        if (!$config) {
            $this->addFlash('error', 'Integration not found');
            return $this->redirectToRoute('app_integrations');
        }

        $integration = $this->integrationRegistry->get($type);
        if (!$integration) {
            $this->addFlash('error', 'Integration type not found');
            return $this->redirectToRoute('app_integrations');
        }

        if ($request->isMethod('POST')) {
            // Update name
            $name = $request->request->get('name');
            if ($name) {
                $config->setName($name);
            }

            // Update credentials if provided
            if ($integration->requiresCredentials()) {
                $credentials = [];
                foreach ($request->request->all() as $key => $value) {
                    if (strpos($key, $type . '_') === 0 && !empty($value)) {
                        $fieldName = str_replace($type . '_', '', $key);
                        $credentials[$fieldName] = $value;
                    }
                }

                if (!empty($credentials)) {
                    $encryptedCredentials = $this->encryptionService->encrypt($credentials);
                    $config->setEncryptedCredentials($encryptedCredentials);
                }
            }

            // Update enabled tools
            foreach ($integration->getTools() as $tool) {
                $toolEnabled = $request->request->get('function_' . $tool->getName()) === 'on';
                if ($toolEnabled) {
                    $config->enableTool($tool->getName());
                } else {
                    $config->disableTool($tool->getName());
                }
            }

            $this->entityManager->persist($config);
            $this->entityManager->flush();

            $this->auditLogService->log(
                'integration.updated',
                $this->getUser(),
                ['type' => $type, 'name' => $name]
            );

            $this->addFlash('success', 'Integration updated successfully');
            return $this->redirectToRoute('app_integrations');
        }

        // Prepare data for template
        $integrationData = [
            'type' => $type,
            'name' => $integration->getName(),
            'instanceName' => $config->getName(),
            'tools' => array_map(fn($tool) => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'enabled' => !$config->isToolDisabled($tool->getName())
            ], $integration->getTools()),
            'credentialFields' => $integration->requiresCredentials() ? $integration->getCredentialFields() : []
        ];

        return $this->render('integration/edit.html.twig', [
            'integration' => $integrationData,
            'config' => $config
        ]);
    }

    #[Route('/{type}/delete', name: 'app_integration_delete', methods: ['POST'])]
    public function delete(string $type, Request $request): Response
    {
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        $config = $this->integrationConfigRepository->findOneByOrganisationAndType($organisation, $type);

        if ($config) {
            $this->auditLogService->log(
                'integration.deleted',
                $this->getUser(),
                ['type' => $type]
            );

            $this->entityManager->remove($config);
            $this->entityManager->flush();

            $this->addFlash('success', 'Integration removed successfully');
        }

        return $this->redirectToRoute('app_integrations');
    }

    #[Route('/{type}/test', name: 'app_integration_test', methods: ['POST'])]
    public function testConnection(string $type, Request $request): JsonResponse
    {
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