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
    public function index(IntegrationRepository $integrationRepository): Response
    {
        $user = $this->getUser();
        $integrations = $integrationRepository->findByUser($user);

        return $this->render('integration/index.html.twig', [
            'integrations' => $integrations,
        ]);
    }

    #[Route('/new', name: 'app_integration_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        EncryptionService $encryptionService,
        AuditLogService $auditLogService
    ): Response {
        if ($request->isMethod('POST')) {
            $type = $request->request->get('type');
            $name = $request->request->get('name');
            $workflowUserId = $request->request->get('workflow_user_id');
            
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
            $integration->setWorkflowUserId($workflowUserId);
            
            $credentials = [];
            if ($type === Integration::TYPE_JIRA) {
                $credentials = [
                    'url' => $request->request->get('jira_url'),
                    'username' => $request->request->get('jira_username'),
                    'api_token' => $request->request->get('jira_api_token'),
                ];
                
                $functions = IntegrationFunction::getJiraFunctions();
            } elseif ($type === Integration::TYPE_CONFLUENCE) {
                $credentials = [
                    'url' => $request->request->get('confluence_url'),
                    'username' => $request->request->get('confluence_username'),
                    'api_token' => $request->request->get('confluence_api_token'),
                ];
                
                $functions = IntegrationFunction::getConfluenceFunctions();
            } elseif ($type === Integration::TYPE_SHAREPOINT) {
                // SharePoint uses OAuth2, so credentials will be set after OAuth flow
                $functions = IntegrationFunction::getSharePointFunctions();
                $credentials = [];
            }
            
            $integration->setEncryptedCredentials($encryptionService->encrypt(json_encode($credentials)));
            $integration->setConfig([]);
            
            foreach ($functions as $functionName => $description) {
                $function = new IntegrationFunction();
                $function->setFunctionName($functionName);
                $function->setDescription($description);
                $function->setActive($request->request->has('function_' . $functionName));
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

        return $this->render('integration/new.html.twig', [
            'types' => Integration::getAvailableTypes(),
            'jira_functions' => IntegrationFunction::getJiraFunctions(),
            'confluence_functions' => IntegrationFunction::getConfluenceFunctions(),
            'sharepoint_functions' => IntegrationFunction::getSharePointFunctions(),
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
            $workflowUserId = $request->request->get('workflow_user_id');
            $active = $request->request->has('active');
            
            $integration->setName($name);
            $integration->setWorkflowUserId($workflowUserId);
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
            
            // Store OAuth tokens
            $credentials = [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires(),
                'tenant_id' => $this->getParameter('azure_tenant_id'),
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
}