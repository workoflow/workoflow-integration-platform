<?php

namespace App\Controller;

use App\Entity\Integration;
use App\Entity\IntegrationFunction;
use App\Repository\IntegrationRepository;
use App\Service\AuditLogService;
use App\Service\EncryptionService;
use App\Service\Integration\JiraService;
use App\Service\Integration\ConfluenceService;
use App\Service\Integration\SharePointService;
use App\Integration\IntegrationRegistry;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/integrations')]
#[IsGranted('ROLE_USER')]
class IntegrationController extends AbstractController
{
    #[Route('/', name: 'app_integrations')]
    public function index(Request $request, IntegrationRepository $integrationRepository): Response
    {
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);
        
        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }
        
        $integrations = $integrationRepository->findByUser($user, $organisation);

        return $this->render('integration/index.html.twig', [
            'integrations' => $integrations,
            'organisation' => $organisation,
        ]);
    }

    #[Route('/new', name: 'app_integration_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        EncryptionService $encryptionService,
        AuditLogService $auditLogService,
        IntegrationRegistry $integrationRegistry
    ): Response {
        if ($request->isMethod('POST')) {
            $type = $request->request->get('type');
            $name = $request->request->get('name');

            $user = $this->getUser();
            $sessionOrgId = $request->getSession()->get('current_organisation_id');
            $organisation = $user->getCurrentOrganisation($sessionOrgId);
            
            if (!$organisation) {
                $this->addFlash('error', 'No organisation selected');
                return $this->redirectToRoute('app_integrations');
            }
            
            $integration = new Integration();
            $integration->setUser($user);
            $integration->setOrganisation($organisation);
            $integration->setType($type);
            $integration->setName($name);
            
            // Get integration definition from registry
            $integrationDef = $integrationRegistry->get($type);
            if (!$integrationDef) {
                $this->addFlash('error', 'Unknown integration type');
                return $this->redirectToRoute('app_integrations');
            }

            // Collect credentials based on integration's field definitions
            $credentials = [];
            foreach ($integrationDef->getCredentialFields() as $field) {
                if ($field->getType() !== 'oauth') {
                    $fieldName = $field->getName();
                    $value = $request->request->get($type . '_' . $fieldName);
                    if ($value !== null) {
                        $credentials[$fieldName] = $value;
                    }
                }
            }

            // Special handling for OAuth integrations
            if ($type === 'sharepoint') {
                // SharePoint uses OAuth2, credentials will be set after OAuth flow
                $credentials = [];
            }
            
            $integration->setEncryptedCredentials($encryptionService->encrypt(json_encode($credentials)));
            $integration->setConfig([]);

            // Add functions from the integration definition
            foreach ($integrationDef->getTools() as $tool) {
                $function = new IntegrationFunction();
                $function->setFunctionName($tool->getName());
                $function->setDescription($tool->getDescription());
                $function->setActive($request->request->has('function_' . $tool->getName()));
                $integration->addFunction($function);
            }
            
            $em->persist($integration);
            $em->flush();
            
            $auditLogService->log(
                'integration.created',
                $this->getUser(),
                ['type' => $type, 'name' => $name]
            );
            
            $this->addFlash('success', 'integration.created.success');
            return $this->redirectToRoute('app_integrations');
        }

        // Build available integrations list from registry (exclude system tools)
        $availableIntegrations = [];

        // Debug: Check what's in the registry
        error_log('Registry has ' . count($integrationRegistry->all()) . ' integrations');
        error_log('User integrations: ' . count($integrationRegistry->getUserIntegrations()));

        foreach ($integrationRegistry->getUserIntegrations() as $integration) {
            $availableIntegrations[$integration->getType()] = [
                'name' => $integration->getName(),
                'credentialFields' => array_map(
                    fn($field) => $field->toArray(),
                    $integration->getCredentialFields()
                ),
                'tools' => array_map(
                    fn($tool) => $tool->toArray(),
                    $integration->getTools()
                )
            ];
        }

        return $this->render('integration/new.html.twig', [
            'integrations' => $availableIntegrations,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_integration_edit', methods: ['GET', 'POST'])]
    public function edit(
        Integration $integration,
        Request $request,
        EntityManagerInterface $em,
        EncryptionService $encryptionService,
        AuditLogService $auditLogService
    ): Response {
        if ($integration->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $active = $request->request->has('active');
            
            $integration->setName($name);
            $integration->setActive($active);
            
            // Update credentials
            $credentials = [];
            if ($integration->getType() === Integration::TYPE_JIRA) {
                $credentials = [
                    'url' => $request->request->get('jira_url'),
                    'username' => $request->request->get('jira_username'),
                    'api_token' => $request->request->get('jira_api_token'),
                ];
            } elseif ($integration->getType() === Integration::TYPE_CONFLUENCE) {
                $credentials = [
                    'url' => $request->request->get('confluence_url'),
                    'username' => $request->request->get('confluence_username'),
                    'api_token' => $request->request->get('confluence_api_token'),
                ];
            } elseif ($integration->getType() === Integration::TYPE_SHAREPOINT) {
                // SharePoint credentials cannot be edited manually (OAuth2)
                $credentials = [];
            }
            
            if (!empty($credentials)) {
                $integration->setEncryptedCredentials($encryptionService->encrypt(json_encode($credentials)));
            }
            
            foreach ($integration->getFunctions() as $function) {
                $function->setActive($request->request->has('function_' . $function->getFunctionName()));
            }
            
            $em->flush();
            
            $auditLogService->log(
                'integration.updated',
                $this->getUser(),
                ['id' => $integration->getId(), 'name' => $name]
            );
            
            $this->addFlash('success', 'integration.updated.success');
            return $this->redirectToRoute('app_integrations');
        }

        // Sync any new functions that were added to the system but don't exist for this integration yet
        $this->syncMissingFunctions($integration, $em);
        
        // Decrypt credentials for display
        $decryptedCredentials = [];
        if ($integration->getEncryptedCredentials()) {
            try {
                $decryptedCredentials = json_decode(
                    $encryptionService->decrypt($integration->getEncryptedCredentials()),
                    true
                );
            } catch (\Exception $e) {
                // If decryption fails, log error but continue with empty credentials
                $this->addFlash('warning', 'Could not decrypt credentials. Please re-enter them.');
            }
        }

        return $this->render('integration/edit.html.twig', [
            'integration' => $integration,
            'credentials' => $decryptedCredentials,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_integration_delete', methods: ['POST'])]
    public function delete(
        Integration $integration,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        if ($integration->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $auditLogService->log(
            'integration.deleted',
            $this->getUser(),
            ['id' => $integration->getId(), 'name' => $integration->getName()]
        );
        
        $em->remove($integration);
        $em->flush();
        
        $this->addFlash('success', 'integration.deleted.success');
        return $this->redirectToRoute('app_integrations');
    }

    #[Route('/{id}/test', name: 'app_integration_test', methods: ['POST'])]
    public function test(
        Integration $integration,
        EncryptionService $encryptionService,
        JiraService $jiraService,
        ConfluenceService $confluenceService,
        SharePointService $sharePointService
    ): JsonResponse {
        if ($integration->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $credentials = json_decode($encryptionService->decrypt($integration->getEncryptedCredentials()), true);
        
        try {
            if ($integration->getType() === Integration::TYPE_JIRA) {
                $result = $jiraService->testConnection($credentials);
            } elseif ($integration->getType() === Integration::TYPE_CONFLUENCE) {
                $result = $confluenceService->testConnection($credentials);
            } elseif ($integration->getType() === Integration::TYPE_SHAREPOINT) {
                $result = $sharePointService->testConnection($credentials);
            } else {
                throw new \Exception('Unknown integration type');
            }
            
            return new JsonResponse(['success' => true, 'message' => 'Connection successful']);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route('/sharepoint/auth/{integrationId}', name: 'connect_microsoft_start')]
    public function connectMicrosoft(
        int $integrationId,
        ClientRegistry $clientRegistry,
        SessionInterface $session
    ): Response {
        // Store integration ID in session for callback
        $session->set('sharepoint_integration_id', $integrationId);
        
        return $clientRegistry
            ->getClient('azure')
            ->redirect([
                'User.Read',
                'offline_access',
                'Sites.Read.All',
                'Files.Read.All'
            ], [
                'prompt' => 'consent'  // Forces consent screen to appear
            ]);
    }

    #[Route('/oauth/callback/microsoft', name: 'connect_microsoft_check')]
    public function connectMicrosoftCheck(
        Request $request,
        ClientRegistry $clientRegistry,
        SessionInterface $session,
        IntegrationRepository $integrationRepository,
        EncryptionService $encryptionService,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        $integrationId = $session->get('sharepoint_integration_id');
        if (!$integrationId) {
            $this->addFlash('error', 'Invalid OAuth callback - no integration ID found');
            return $this->redirectToRoute('app_integrations');
        }
        
        $integration = $integrationRepository->find($integrationId);
        if (!$integration || $integration->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration not found or access denied');
            return $this->redirectToRoute('app_integrations');
        }
        
        try {
            $client = $clientRegistry->getClient('azure');
            $accessToken = $client->getAccessToken();

            // Extract tenant_id from the access token
            // Microsoft tokens have the tenant ID in the 'tid' claim
            $tokenParts = explode('.', $accessToken->getToken());
            $tenantId = null;

            if (count($tokenParts) >= 2) {
                try {
                    // Decode the payload (second part of JWT)
                    $payload = json_decode(base64_decode(strtr($tokenParts[1], '-_', '+/')), true);
                    $tenantId = $payload['tid'] ?? null;

                    if (!$tenantId) {
                        // Fallback to issuer parsing if tid not present
                        // Issuer format: https://login.microsoftonline.com/{tenant_id}/v2.0
                        if (isset($payload['iss'])) {
                            preg_match('/\/([a-f0-9-]+)\/v2\.0$/', $payload['iss'], $matches);
                            $tenantId = $matches[1] ?? null;
                        }
                    }
                } catch (\Exception $e) {
                    // If decoding fails, fall back to configured tenant
                    error_log('Failed to decode token for tenant ID: ' . $e->getMessage());
                }
            }

            // Use extracted tenant_id or fall back to configured value
            if (!$tenantId) {
                $tenantId = $this->getParameter('azure_tenant_id');
            }

            // Store OAuth tokens with the correct tenant_id
            $credentials = [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires(),
                'tenant_id' => $tenantId,
                'client_id' => $this->getParameter('azure_client_id'),
                'client_secret' => $this->getParameter('azure_client_secret')
            ];
            
            $integration->setEncryptedCredentials($encryptionService->encrypt(json_encode($credentials)));
            $em->flush();
            
            $auditLogService->log(
                'integration.sharepoint_connected',
                $this->getUser(),
                ['integration_id' => $integrationId]
            );
            
            $this->addFlash('success', 'SharePoint integration connected successfully');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to connect SharePoint: ' . $e->getMessage());
        }
        
        $session->remove('sharepoint_integration_id');
        return $this->redirectToRoute('app_integrations');
    }
    
    /**
     * Sync functions for an integration
     * This ensures that functions match what's defined in the code:
     * - Adds any new functions as inactive
     * - Removes functions that no longer exist in the code
     */
    private function syncMissingFunctions(Integration $integration, EntityManagerInterface $em): void
    {
        // Get all available functions for this integration type
        $availableFunctions = [];
        if ($integration->getType() === Integration::TYPE_JIRA) {
            $availableFunctions = IntegrationFunction::getJiraFunctions();
        } elseif ($integration->getType() === Integration::TYPE_CONFLUENCE) {
            $availableFunctions = IntegrationFunction::getConfluenceFunctions();
        } elseif ($integration->getType() === Integration::TYPE_SHAREPOINT) {
            $availableFunctions = IntegrationFunction::getSharePointFunctions();
        }
        
        $hasChanges = false;
        
        // Build a map of existing functions for easier lookup
        $existingFunctions = [];
        foreach ($integration->getFunctions() as $function) {
            $existingFunctions[$function->getFunctionName()] = $function;
        }
        
        // Add any missing functions as inactive
        foreach ($availableFunctions as $functionName => $description) {
            if (!isset($existingFunctions[$functionName])) {
                $newFunction = new IntegrationFunction();
                $newFunction->setIntegration($integration);
                $newFunction->setFunctionName($functionName);
                $newFunction->setDescription($description);
                $newFunction->setActive(false); // New functions are inactive by default
                
                $integration->addFunction($newFunction);
                $em->persist($newFunction);
                $hasChanges = true;
            } else {
                // Update description if it changed
                if ($existingFunctions[$functionName]->getDescription() !== $description) {
                    $existingFunctions[$functionName]->setDescription($description);
                    $hasChanges = true;
                }
            }
        }
        
        // Remove functions that no longer exist in the code
        foreach ($existingFunctions as $functionName => $function) {
            if (!array_key_exists($functionName, $availableFunctions)) {
                $integration->removeFunction($function);
                $em->remove($function);
                $hasChanges = true;
            }
        }
        
        // Flush changes if any modifications were made
        if ($hasChanges) {
            $em->flush();
        }
    }

    #[Route('/api/fields/{type}', name: 'app_integration_fields_api', methods: ['GET'])]
    public function getIntegrationFields(
        string $type,
        IntegrationRegistry $integrationRegistry
    ): JsonResponse {
        $integration = $integrationRegistry->get($type);

        if (!$integration) {
            return new JsonResponse(['error' => 'Integration type not found'], 404);
        }

        return new JsonResponse([
            'name' => $integration->getName(),
            'credentialFields' => array_map(
                fn($field) => $field->toArray(),
                $integration->getCredentialFields()
            ),
            'tools' => array_map(
                fn($tool) => $tool->toArray(),
                $integration->getTools()
            )
        ]);
    }
}