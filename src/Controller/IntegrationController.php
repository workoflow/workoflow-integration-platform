<?php

namespace App\Controller;

use App\Entity\IntegrationConfig;
use App\Entity\User;
use App\Form\IntegrationConfigType;
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
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/tools')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class IntegrationController extends AbstractController
{
    public function __construct(
        private IntegrationConfigRepository $integrationConfigRepository,
        private IntegrationRegistry $integrationRegistry,
        private EncryptionService $encryptionService,
        private AuditLogService $auditLogService,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator
    ) {
    }

    #[Route('/', name: 'app_tools')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        // Get workflow_user_id from the user's organization relationship
        $userOrg = null;
        foreach ($user->getUserOrganisations() as $userOrganisation) {
            if ($userOrganisation->getOrganisation() === $organisation) {
                $userOrg = $userOrganisation;
                break;
            }
        }
        $workflowUserId = $userOrg ? $userOrg->getWorkflowUserId() : null;

        // Get all integrations from registry (code-defined)
        $allIntegrations = $this->integrationRegistry->getAllIntegrations();

        // Get user configs from database (filtered by workflow_user_id)
        $configs = $this->integrationConfigRepository->findByOrganisationAndWorkflowUser($organisation, $workflowUserId);

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

    #[Route('/setup/{type}', name: 'app_tool_setup', methods: ['GET', 'POST'])]
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
            return $this->redirectToRoute('app_tools');
        }

        // System tools don't need setup
        if (!$integration->requiresCredentials()) {
            $this->addFlash('info', 'This integration does not require setup');
            return $this->redirectToRoute('app_tools');
        }

        // Get workflow_user_id from the user's organization relationship
        $userOrg = null;
        foreach ($user->getUserOrganisations() as $userOrganisation) {
            if ($userOrganisation->getOrganisation() === $organisation) {
                $userOrg = $userOrganisation;
                break;
            }
        }
        $workflowUserId = $userOrg ? $userOrg->getWorkflowUserId() : null;

        // Check if editing existing or creating new
        $instanceId = $request->query->get('instance');
        $config = null;
        $existingCredentials = [];

        if ($instanceId) {
            $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($instanceId);
            if (!$config || $config->getOrganisation()->getId() !== $organisation->getId() || $config->getUser()->getId() !== $user->getId()) {
                $this->addFlash('error', 'Instance not found');
                return $this->redirectToRoute('app_tools');
            }

            // Decrypt existing credentials for display (with masking for sensitive fields)
            if ($config->hasCredentials()) {
                try {
                    $decryptedCredentials = json_decode(
                        $this->encryptionService->decrypt($config->getEncryptedCredentials()),
                        true
                    );

                    // Prepare credentials for display - mask sensitive fields like api_token
                    foreach ($decryptedCredentials as $key => $value) {
                        if ($key === 'api_token' || $key === 'password' || $key === 'client_secret') {
                            // Don't show the actual token, just indicate it exists
                            $existingCredentials[$key] = ''; // Leave empty, we'll show a placeholder
                            $existingCredentials[$key . '_exists'] = true;
                        } else {
                            // Non-sensitive fields like URL and email can be shown
                            $existingCredentials[$key] = $value;
                        }
                    }
                    // Also set name for form
                    $existingCredentials['name'] = $config->getName();
                } catch (\Exception $e) {
                    // If decryption fails, just continue with empty credentials
                    $this->addFlash('warning', 'Could not load existing credentials');
                }
            } else {
                $existingCredentials['name'] = $config->getName();
            }
        }

        // Get existing instances for this type (filtered by workflow_user_id)
        $existingInstances = $this->integrationConfigRepository->findByOrganisationTypeAndWorkflowUser($organisation, $type, $workflowUserId);

        // Create form
        $form = $this->createForm(IntegrationConfigType::class, $existingCredentials, [
            'integration' => $integration,
            'existing_credentials' => $existingCredentials,
            'is_edit' => $config !== null
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();
            $name = $formData['name'];

            // Check for duplicate names
            foreach ($existingInstances as $existing) {
                if ($existing->getName() === $name && (!$config || $existing->getId() !== $config->getId())) {
                    $this->addFlash('error', $this->translator->trans('validation.name_duplicate'));
                    $form->get('name')->addError(new \Symfony\Component\Form\FormError($this->translator->trans('validation.name_duplicate')));

                    return $this->render('integration/setup.html.twig', [
                        'form' => $form,
                        'integration' => $integration,
                        'config' => $config,
                        'organisation' => $organisation,
                        'credentialFields' => $integration->getCredentialFields(),
                        'existingInstances' => $existingInstances,
                        'isEdit' => $config !== null
                    ]);
                }
            }

            // Build credentials array
            $credentials = [];
            $hasOAuthField = false;
            $credentialsModified = false; // Track if any credentials were changed

            // If editing, start with existing credentials
            if ($config && $config->hasCredentials()) {
                try {
                    $credentials = json_decode(
                        $this->encryptionService->decrypt($config->getEncryptedCredentials()),
                        true
                    );
                } catch (\Exception $e) {
                    $credentials = [];
                }
            }

            // Process credential fields
            foreach ($integration->getCredentialFields() as $field) {
                if ($field->getType() === 'oauth') {
                    $hasOAuthField = true;
                    continue;
                }

                $value = $formData[$field->getName()] ?? null;

                // For sensitive fields, only update if new value provided
                if (in_array($field->getName(), ['api_token', 'password', 'client_secret'])) {
                    if (!empty($value)) {
                        $credentials[$field->getName()] = $value;
                        $credentialsModified = true; // Mark as modified
                    }
                } else {
                    // For non-sensitive fields, always update
                    if ($value !== null) {
                        // Normalize URLs
                        if ($field->getName() === 'url' && !empty($value)) {
                            // Remove trailing slash
                            $value = rtrim($value, '/');
                        }
                        $credentials[$field->getName()] = $value;
                        // Check if value actually changed
                        if (!$config || !isset($credentials[$field->getName()]) || $credentials[$field->getName()] !== $value) {
                            $credentialsModified = true;
                        }
                    }
                }
            }

            // For OAuth integrations, start OAuth flow immediately
            if ($hasOAuthField && !$config) {
                $tempConfig = new IntegrationConfig();
                $tempConfig->setOrganisation($organisation);
                $tempConfig->setIntegrationType($type);
                $tempConfig->setUser($user);
                $tempConfig->setName($name);
                $tempConfig->setActive(false);

                $this->entityManager->persist($tempConfig);
                $this->entityManager->flush();

                $request->getSession()->set('oauth_flow_integration', $tempConfig->getId());

                return $this->redirectToRoute('app_tool_oauth_microsoft_start', [
                    'configId' => $tempConfig->getId()
                ]);
            }

            // Validate credentials for non-OAuth integrations
            // Only validate if:
            // 1. All required fields are present, AND
            // 2. Either creating new integration OR credentials were modified in edit mode
            if (!$hasOAuthField) {
                $hasAllFields = true;
                foreach ($integration->getCredentialFields() as $field) {
                    if ($field->getType() === 'oauth') {
                        continue;
                    }
                    if ($field->isRequired() && empty($credentials[$field->getName()])) {
                        $hasAllFields = false;
                        break;
                    }
                }

                // Skip validation if editing and no credentials were modified
                $shouldValidate = $hasAllFields && (!$config || $credentialsModified);

                if ($shouldValidate && !$integration->validateCredentials($credentials)) {
                    $this->addFlash('error', $this->translator->trans('integration.connection_failed'));
                    $form->addError(new \Symfony\Component\Form\FormError($this->translator->trans('integration.connection_failed_message')));

                    return $this->render('integration/setup.html.twig', [
                        'form' => $form,
                        'integration' => $integration,
                        'config' => $config,
                        'organisation' => $organisation,
                        'credentialFields' => $integration->getCredentialFields(),
                        'existingInstances' => $existingInstances,
                        'isEdit' => $config !== null
                    ]);
                }
            }

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
                $toolsToDisable = ['sharepoint_list_files', 'sharepoint_download_file', 'sharepoint_get_list_items'];
                foreach ($toolsToDisable as $toolName) {
                    $config->disableTool($toolName);
                }
            }

            $this->entityManager->persist($config);
            $this->entityManager->flush();

            $this->auditLogService->log(
                $instanceId ? 'integration.updated' : 'integration.setup',
                $user,
                ['type' => $type, 'name' => $name]
            );

            $this->addFlash('success', 'Integration "' . $name . '" configured successfully');
            return $this->redirectToRoute('app_tools');
        }

        return $this->render('integration/setup.html.twig', [
            'form' => $form,
            'integration' => $integration,
            'config' => $config,
            'organisation' => $organisation,
            'credentialFields' => $integration->getCredentialFields(),
            'existingInstances' => $existingInstances,
            'isEdit' => $config !== null
        ]);
    }


    #[Route('/{type}/toggle-tool', name: 'app_tool_toggle_tool', methods: ['POST'])]
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

        // Get specific instance and verify ownership
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($instanceId);
        if (!$config || $config->getOrganisation()->getId() !== $organisation->getId() || $config->getUser()->getId() !== $user->getId()) {
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

    #[Route('/{type}/toggle', name: 'app_tool_toggle', methods: ['POST'])]
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

            if (!$config || $config->getUser()->getId() !== $user->getId()) {
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


    #[Route('/delete/{instanceId}', name: 'app_tool_delete', methods: ['POST'])]
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

        if ($config && $config->getOrganisation()->getId() === $organisation->getId() && $config->getUser()->getId() === $user->getId()) {
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

        return $this->redirectToRoute('app_tools');
    }

    #[Route('/{type}/test', name: 'app_tool_test', methods: ['POST'])]
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

        // Get instance ID from request
        $instanceId = $request->request->get('instance');
        if (!$instanceId) {
            return $this->json(['success' => false, 'message' => 'Instance ID required']);
        }

        // Get specific config by instance ID
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($instanceId);

        if (!$config || $config->getOrganisation()->getId() !== $organisation->getId() || $config->getUser()->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'message' => 'Instance not found']);
        }

        if (!$config->hasCredentials()) {
            return $this->json(['success' => false, 'message' => 'No credentials configured']);
        }

        try {
            // Decrypt credentials
            $credentials = json_decode(
                $this->encryptionService->decrypt($config->getEncryptedCredentials()),
                true
            );

            // For Confluence, use detailed testing
            if ($type === 'confluence') {
                $confluenceIntegration = $integration;
                // Access the service through reflection to get detailed results
                $reflection = new \ReflectionClass($confluenceIntegration);
                $property = $reflection->getProperty('confluenceService');
                $property->setAccessible(true);
                $confluenceService = $property->getValue($confluenceIntegration);

                $result = $confluenceService->testConnectionDetailed($credentials);

                if ($result['success']) {
                    return $this->json([
                        'success' => true,
                        'message' => $result['message'],
                        'details' => $result['details'],
                        'tested_endpoints' => $result['tested_endpoints']
                    ]);
                } else {
                    return $this->json([
                        'success' => false,
                        'message' => $result['message'],
                        'details' => $result['details'],
                        'suggestion' => $result['suggestion'],
                        'tested_endpoints' => $result['tested_endpoints']
                    ]);
                }
            }

            // For other integrations, use standard validation
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
