<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AuditLogService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/channel')]
#[IsGranted('ROLE_USER')]
class ChannelController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogService $auditLogService,
        private HttpClientInterface $httpClient,
        private EncryptionService $encryptionService
    ) {
    }

    #[Route('', name: 'app_channel')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_channel_create');
        }

        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        return $this->render('channel/index.html.twig', [
            'organisation' => $organisation,
            'userOrganisation' => $userOrganisation,
            'user' => $user,
        ]);
    }

    #[Route('/update', name: 'app_channel_update', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_channel_create');
        }

        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        // Get form data
        $data = $request->request->all();

        // Update Organisation fields
        if (isset($data['name'])) {
            $organisation->setName($data['name']);
        }

        if (isset($data['webhook_type'])) {
            $organisation->setWebhookType($data['webhook_type']);
        }

        if (isset($data['webhook_url'])) {
            $organisation->setWebhookUrl($data['webhook_url']);
        }

        if (isset($data['workflow_url'])) {
            $organisation->setWorkflowUrl($data['workflow_url']);
        }

        // Handle encrypted N8N API Key
        if (isset($data['n8n_api_key']) && !empty($data['n8n_api_key'])) {
            $encryptedKey = $this->encryptionService->encrypt($data['n8n_api_key']);
            $organisation->setEncryptedN8nApiKey($encryptedKey);
        }

        // Handle organisation type
        if (isset($data['organisation_type'])) {
            $organisation->setOrganisationType($data['organisation_type']);
        }

        // Handle MS Teams fields
        if (isset($data['microsoft_app_type'])) {
            $organisation->setMicrosoftAppType($data['microsoft_app_type']);
        }

        if (isset($data['microsoft_app_id'])) {
            $organisation->setMicrosoftAppId($data['microsoft_app_id']);
        }

        // Handle encrypted MS Teams password
        if (isset($data['microsoft_app_password']) && !empty($data['microsoft_app_password'])) {
            $encryptedPassword = $this->encryptionService->encrypt($data['microsoft_app_password']);
            $organisation->setEncryptedMicrosoftAppPassword($encryptedPassword);
        }

        if (isset($data['microsoft_app_tenant_id'])) {
            $organisation->setMicrosoftAppTenantId($data['microsoft_app_tenant_id']);
        }

        // Update UserOrganisation fields
        if ($userOrganisation && isset($data['system_prompt'])) {
            $userOrganisation->setSystemPrompt($data['system_prompt']);
        }

        try {
            $this->entityManager->flush();

            $this->auditLogService->log(
                'channel.updated',
                $user,
                [
                    'organisation_id' => $organisation->getId(),
                    'webhook_type' => $organisation->getWebhookType(),
                    'has_webhook_url' => !empty($organisation->getWebhookUrl()),
                    'has_system_prompt' => !empty($userOrganisation?->getSystemPrompt())
                ]
            );

            $this->addFlash('success', 'Channel settings have been saved successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save settings: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_channel');
    }

    #[Route('/api/n8n-workflow/{orgId}', name: 'app_channel_n8n_workflow', methods: ['GET'])]
    public function fetchN8nWorkflow(string $orgId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation || $organisation->getUuid() !== $orgId) {
            return new JsonResponse(['error' => 'Invalid organisation'], 403);
        }

        if ($organisation->getWebhookType() !== 'N8N') {
            return new JsonResponse(['error' => 'N8N not configured'], 400);
        }

        try {
            // Use workflowUrl if available, fallback to webhookUrl for backward compatibility
            $workflowUrl = $organisation->getWorkflowUrl() ?? $organisation->getWebhookUrl();

            if (!$workflowUrl) {
                return new JsonResponse(['error' => 'Workflow URL not configured'], 400);
            }

            // Extract workflow ID from URL
            // Example: http://localhost:5678/workflow/xRYAqofSE2ljb0d5
            preg_match('/\/workflow\/([a-zA-Z0-9]+)/', $workflowUrl, $matches);
            $workflowId = $matches[1] ?? null;

            if (!$workflowId) {
                return new JsonResponse(['error' => 'Invalid workflow URL format. URL must contain /workflow/{id}'], 400);
            }

            // Parse base URL from workflow URL
            $parsedUrl = parse_url($workflowUrl);
            $host = $parsedUrl['host'];

            // If localhost and running in Docker, use host.docker.internal
            if ($host === 'localhost' || $host === '127.0.0.1') {
                if (file_exists('/.dockerenv') || is_file('/proc/self/cgroup') && strpos(file_get_contents('/proc/self/cgroup'), 'docker') !== false) {
                    $host = 'host.docker.internal';
                }
            }

            $baseUrl = $parsedUrl['scheme'] . '://' . $host . ':' . ($parsedUrl['port'] ?? 5678);
            $apiUrl = $baseUrl . '/api/v1/workflows/' . $workflowId;

            // Fetch workflow from N8N API
            if (!$organisation->getEncryptedN8nApiKey()) {
                return new JsonResponse(['error' => 'N8N API key not configured'], 400);
            }

            // Decrypt N8N API Key
            $n8nApiKey = $this->encryptionService->decrypt($organisation->getEncryptedN8nApiKey());

            $response = $this->httpClient->request('GET', $apiUrl, [
                'headers' => [
                    'X-N8N-API-KEY' => $n8nApiKey,
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() !== 200) {
                return new JsonResponse(['error' => 'Failed to fetch workflow from N8N'], $response->getStatusCode());
            }

            $workflowData = $response->toArray();

            // Return raw workflow JSON for n8n-demo component
            return new JsonResponse([
                'workflow' => $workflowData,
                'error' => null
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'workflow' => null,
                'error' => 'Failed to fetch workflow: ' . $e->getMessage()
            ], 500);
        }
    }
}
