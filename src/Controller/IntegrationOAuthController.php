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

    // ========================================
    // HubSpot OAuth2 Flow
    // ========================================

    #[Route('/hubspot/start/{configId}', name: 'app_tool_oauth_hubspot_start')]
    public function hubspotStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('hubspot_oauth_config_id', $configId);

        // Redirect to HubSpot OAuth with CRM scopes
        return $clientRegistry
            ->getClient('hubspot')
            ->redirect([
                'crm.objects.contacts.read',
                'crm.objects.contacts.write',
                'crm.objects.companies.read',
                'crm.objects.companies.write',
                'crm.objects.deals.read',
                'crm.objects.deals.write',
            ], []);
    }

    #[Route('/callback/hubspot', name: 'app_tool_oauth_hubspot_callback')]
    public function hubspotCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('hubspot_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                // If this was initial setup and user cancelled, remove the temporary config
                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('hubspot_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'HubSpot setup cancelled. HubSpot authorization is required to use this integration.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('hubspot_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

        // Continue with normal flow
        $configId = $request->getSession()->get('hubspot_oauth_config_id');

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
            $client = $clientRegistry->getClient('hubspot');

            // Get the access token
            $accessToken = $client->getAccessToken();

            // Store the credentials
            $credentials = [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires(),
            ];

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('hubspot_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'HubSpot integration created and connected successfully!');
            } else {
                $this->addFlash('success', 'HubSpot integration connected successfully!');
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

            $this->addFlash('error', 'Failed to connect to HubSpot: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }

    // ========================================
    // SAP C4C OAuth2 Flow (User Delegation via Azure AD)
    // ========================================

    #[Route('/sap-c4c/start/{configId}', name: 'app_tool_oauth_sap_c4c_start')]
    public function sapC4cStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Verify this is an OAuth2 mode config
        $existingCredentials = [];
        if ($config->getEncryptedCredentials()) {
            $decrypted = $this->encryptionService->decrypt($config->getEncryptedCredentials());
            $existingCredentials = json_decode($decrypted, true) ?: [];
        }

        if (($existingCredentials['auth_mode'] ?? 'basic') !== 'oauth2') {
            $this->addFlash('error', 'This integration is not configured for OAuth2 authentication');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('sap_c4c_oauth_config_id', $configId);

        // Get the Azure AD App ID URI from stored credentials (needed for scope)
        $appIdUri = $existingCredentials['azure_app_id_uri'] ?? '';

        // Redirect to Azure AD OAuth with appropriate scopes
        // We need offline_access for refresh token, and the SAP C4C app scope
        $scopes = [
            'openid',
            'profile',
            'email',
            'offline_access',
        ];

        // Add the SAP C4C App ID URI scope if provided
        if (!empty($appIdUri)) {
            $scopes[] = $appIdUri . '/.default';
        }

        return $clientRegistry
            ->getClient('azure')
            ->redirect($scopes, []);
    }

    #[Route('/callback/sap-c4c', name: 'app_tool_oauth_sap_c4c_callback')]
    public function sapC4cCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('sap_c4c_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('sap_c4c_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'SAP C4C setup cancelled. Azure AD authorization is required for OAuth2 user delegation.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('sap_c4c_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

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

            // Get the access token (includes refresh token due to offline_access scope)
            $accessToken = $client->getAccessToken();

            // Get existing credentials to preserve OAuth2 config fields
            $existingCredentials = [];
            if ($config->getEncryptedCredentials()) {
                $decrypted = $this->encryptionService->decrypt($config->getEncryptedCredentials());
                $existingCredentials = json_decode($decrypted, true) ?: [];
            }

            // Merge Azure OAuth tokens with existing credentials
            $credentials = array_merge($existingCredentials, [
                'azure_access_token' => $accessToken->getToken(),
                'azure_refresh_token' => $accessToken->getRefreshToken(),
                'azure_expires_at' => $accessToken->getExpires(),
                'azure_token_acquired_at' => time(),
            ]);

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('sap_c4c_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'SAP C4C integration created and connected via Azure AD successfully!');
            } else {
                $this->addFlash('success', 'SAP C4C integration connected via Azure AD successfully!');
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

            $this->addFlash('error', 'Failed to connect to Azure AD: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }

    // ========================================
    // SAP SAC OAuth2 Flow (User Delegation via Azure AD)
    // ========================================

    #[Route('/sap-sac/start/{configId}', name: 'app_tool_oauth_sap_sac_start')]
    public function sapSacStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Verify this is an OAuth2 user delegation mode config
        $existingCredentials = [];
        if ($config->getEncryptedCredentials()) {
            $decrypted = $this->encryptionService->decrypt($config->getEncryptedCredentials());
            $existingCredentials = json_decode($decrypted, true) ?: [];
        }

        if (($existingCredentials['auth_mode'] ?? 'client_credentials') !== 'user_delegation') {
            $this->addFlash('error', 'This integration is not configured for user delegation authentication');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('sap_sac_oauth_config_id', $configId);

        // Get the Azure AD App ID URI from stored credentials (needed for scope)
        $appIdUri = $existingCredentials['azure_app_id_uri'] ?? '';

        // Redirect to Azure AD OAuth with appropriate scopes
        $scopes = [
            'openid',
            'profile',
            'email',
            'offline_access',
        ];

        // Add the SAP SAC App ID URI scope if provided
        if (!empty($appIdUri)) {
            $scopes[] = $appIdUri . '/.default';
        }

        return $clientRegistry
            ->getClient('azure')
            ->redirect($scopes, []);
    }

    #[Route('/callback/sap-sac', name: 'app_tool_oauth_sap_sac_callback')]
    public function sapSacCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('sap_sac_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('sap_sac_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'SAP SAC setup cancelled. Azure AD authorization is required for user delegation.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('sap_sac_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

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

            // Get the access token (includes refresh token due to offline_access scope)
            $accessToken = $client->getAccessToken();

            // Get existing credentials to preserve OAuth2 config fields
            $existingCredentials = [];
            if ($config->getEncryptedCredentials()) {
                $decrypted = $this->encryptionService->decrypt($config->getEncryptedCredentials());
                $existingCredentials = json_decode($decrypted, true) ?: [];
            }

            // Merge Azure OAuth tokens with existing credentials
            $credentials = array_merge($existingCredentials, [
                'azure_access_token' => $accessToken->getToken(),
                'azure_refresh_token' => $accessToken->getRefreshToken(),
                'azure_expires_at' => $accessToken->getExpires(),
                'azure_token_acquired_at' => time(),
            ]);

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('sap_sac_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'SAP SAC integration created and connected via Azure AD successfully!');
            } else {
                $this->addFlash('success', 'SAP SAC integration connected via Azure AD successfully!');
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

            $this->addFlash('error', 'Failed to connect to Azure AD: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }
}
