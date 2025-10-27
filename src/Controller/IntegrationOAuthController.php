<?php

namespace App\Controller;

use App\Entity\IntegrationConfig;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/integrations/oauth')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class IntegrationOAuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EncryptionService $encryptionService
    ) {
    }

    #[Route('/microsoft/start/{configId}', name: 'app_tool_oauth_microsoft_start')]
    public function microsoftStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('microsoft_oauth_config_id', $configId);

        // Redirect to Microsoft OAuth
        return $clientRegistry
            ->getClient('azure')
            ->redirect([
                'openid',
                'profile',
                'email',
                'offline_access',
                'https://graph.microsoft.com/Sites.Read.All',
                'https://graph.microsoft.com/Files.Read.All',
                'https://graph.microsoft.com/User.Read'
            ], []);
    }

    #[Route('/callback/microsoft', name: 'app_tool_oauth_microsoft_callback')]
    public function microsoftCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('microsoft_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                // If this was initial setup and user cancelled, remove the temporary config
                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('microsoft_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'SharePoint setup cancelled. Microsoft authorization is required to use this integration.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('microsoft_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

        // Continue with normal flow
        $configId = $request->getSession()->get('microsoft_oauth_config_id');

        if (!$configId) {
            $this->addFlash('error', 'OAuth session expired. Please try again.');
            return $this->redirectToRoute('app_skills');
        }

        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        try {
            // Get the OAuth2 client
            $client = $clientRegistry->getClient('azure');

            // Get the access token
            $accessToken = $client->getAccessToken();

            // Store the credentials
            $credentials = [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires(),
                'tenant_id' => $accessToken->getValues()['tenant_id'] ?? 'common',
                'client_id' => $_ENV['AZURE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['AZURE_CLIENT_SECRET'] ?? ''
            ];

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            // Auto-disable less useful tools for SharePoint on first setup
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if (
                $config->getIntegrationType() === 'sharepoint' &&
                $oauthFlowIntegration &&
                $oauthFlowIntegration == $configId &&
                empty($config->getDisabledTools())
            ) {
                // These tools are less useful for AI agents, disable by default
                $toolsToDisable = [
                    'sharepoint_list_files',      // Agents should search, not browse
                    'sharepoint_download_file',    // Only returns URL, agent can't fetch it
                    'sharepoint_get_list_items'    // Too technical for most use cases
                ];

                foreach ($toolsToDisable as $toolName) {
                    $config->disableTool($toolName);
                }

                error_log('Auto-disabled less useful SharePoint tools for new OAuth integration: ' . implode(', ', $toolsToDisable));
            }

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('microsoft_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'SharePoint integration created and connected successfully!');
            } else {
                $this->addFlash('success', 'SharePoint integration connected successfully!');
            }

            return $this->redirectToRoute('app_skills');
        } catch (\Exception $e) {
            // If this was initial setup and failed, remove the temporary config
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->entityManager->remove($config);
                $this->entityManager->flush();
            }

            $this->addFlash('error', 'Failed to connect to SharePoint: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }
}
