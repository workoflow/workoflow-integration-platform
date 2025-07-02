<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class TokenRefreshSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token || !$token->getUser() instanceof User) {
            return;
        }

        /** @var User $user */
        $user = $token->getUser();

        // Check if token needs refresh (refresh if expires in less than 5 minutes)
        if (!$user->getTokenExpiresAt() || !$user->getRefreshToken()) {
            return;
        }

        $expiresIn = $user->getTokenExpiresAt()->getTimestamp() - time();
        if ($expiresIn > 300) { // More than 5 minutes remaining
            return;
        }

        try {
            $this->refreshToken($user);
        } catch (\Exception $e) {
            $this->logger->error('Failed to refresh OAuth token', [
                'user' => $user->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    private function refreshToken(User $user): void
    {
        $client = $this->clientRegistry->getClient('google');
        $provider = $client->getOAuth2Provider();

        // Use the refresh token to get a new access token
        $newAccessToken = $provider->getAccessToken('refresh_token', [
            'refresh_token' => $user->getRefreshToken()
        ]);

        // Update user with new tokens
        $user->setAccessToken($newAccessToken->getToken());
        
        // Update refresh token if a new one was provided
        if ($newAccessToken->getRefreshToken()) {
            $user->setRefreshToken($newAccessToken->getRefreshToken());
        }

        // Update expiry time
        if ($newAccessToken->getExpires()) {
            $expiresAt = new \DateTime();
            $expiresAt->setTimestamp($newAccessToken->getExpires());
            $user->setTokenExpiresAt($expiresAt);
        }

        $this->entityManager->flush();

        $this->logger->info('Successfully refreshed OAuth token', [
            'user' => $user->getId()
        ]);
    }
}