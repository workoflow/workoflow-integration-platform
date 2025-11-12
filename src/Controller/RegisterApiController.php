<?php

namespace App\Controller;

use App\Service\MagicLinkService;
use App\Service\UserRegistrationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/api')]
class RegisterApiController extends AbstractController
{
    public function __construct(
        private UserRegistrationService $userRegistrationService,
        private MagicLinkService $magicLinkService,
        private LoggerInterface $logger,
        private string $apiAuthUser,
        private string $apiAuthPassword
    ) {
    }

    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        // Validate Basic Auth
        if (!$this->validateBasicAuth($request)) {
            return $this->json([
                'success' => false,
                'error' => 'Unauthorized'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Get request data
        $data = json_decode($request->getContent(), true);

        // Validate required fields
        $name = $data['name'] ?? null;
        $orgUuid = $data['org_uuid'] ?? null;
        $workflowUserId = $data['workflow_user_id'] ?? null;

        if (!$name || !$orgUuid || !$workflowUserId) {
            return $this->json([
                'success' => false,
                'error' => 'Missing required fields. Required: name, org_uuid, workflow_user_id'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Optional organisation name
        $orgName = $data['org_name'] ?? null;

        // Optional channel parameters
        $channelUuid = $data['channel_uuid'] ?? null;
        $channelName = $data['channel_name'] ?? null;

        try {
            // Create or find organisation
            $organisation = $this->userRegistrationService->createOrFindOrganisation($orgUuid, $orgName);

            // Use provided email or generate from name as fallback
            $email = $data['email'] ?? $this->userRegistrationService->generateEmailFromName($name);

            // Create or update user
            $user = $this->userRegistrationService->createOrUpdateUser(
                $name,
                $email,
                $organisation,
                $workflowUserId
            );

            // Handle channel association if provided
            if ($channelUuid || $channelName) {
                $channel = $this->userRegistrationService->createOrFindChannel($channelUuid, $channelName);
                if ($channel) {
                    $this->userRegistrationService->addUserToChannel($user, $channel);
                }
            }

            // Generate magic link token (with email if provided)
            $token = $this->magicLinkService->generateToken($name, $orgUuid, $workflowUserId, $email);

            // Build magic link URL
            $baseUrl = $this->getBaseUrl($request);
            $magicLinkUrl = $baseUrl . '/auth/magic-link?token=' . $token;

            $this->logger->info('User registered via API', [
                'name' => $name,
                'email' => $email,
                'org_uuid' => $orgUuid,
                'workflow_user_id' => $workflowUserId,
                'user_id' => $user->getId(),
                'channel_uuid' => $channelUuid,
                'channel_name' => $channelName
            ]);

            $response = [
                'success' => true,
                'magic_link' => $magicLinkUrl,
                'user_id' => $user->getId(),
                'email' => $email,
                'organisation' => [
                    'id' => $organisation->getId(),
                    'uuid' => $organisation->getUuid(),
                    'name' => $organisation->getName()
                ]
            ];

            // Include channel info in response if channel was created
            if (isset($channel)) {
                $response['channel'] = [
                    'id' => $channel->getId(),
                    'uuid' => $channel->getUuid(),
                    'name' => $channel->getName()
                ];
            }

            return $this->json($response, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Failed to register user via API', [
                'error' => $e->getMessage(),
                'name' => $name,
                'org_uuid' => $orgUuid,
                'workflow_user_id' => $workflowUserId
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Registration failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Validate Basic Auth credentials
     */
    private function validateBasicAuth(Request $request): bool
    {
        $authHeader = $request->headers->get('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $credentials = base64_decode(substr($authHeader, 6));
        [$username, $password] = explode(':', $credentials, 2);

        return $username === $this->apiAuthUser && $password === $this->apiAuthPassword;
    }

    /**
     * Get the base URL for the application
     */
    private function getBaseUrl(Request $request): string
    {
        $scheme = $request->getScheme();
        $host = $request->getHttpHost();

        // In production, force HTTPS
        if ($this->getParameter('kernel.environment') === 'prod') {
            $scheme = 'https';
        }

        return $scheme . '://' . $host;
    }
}
