<?php

namespace App\Security;

use App\Repository\UserOrganisationRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class PromptApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UserOrganisationRepository $userOrganisationRepository
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Support requests with X-Prompt-Token header or Bearer token
        return $request->headers->has('X-Prompt-Token')
            || str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->extractToken($request);

        if ($token === null || $token === '') {
            throw new CustomUserMessageAuthenticationException('No API token provided');
        }

        $userOrganisation = $this->userOrganisationRepository->findOneBy([
            'personalAccessToken' => $token,
        ]);

        if ($userOrganisation === null) {
            throw new CustomUserMessageAuthenticationException('Invalid API token');
        }

        $user = $userOrganisation->getUser();

        if ($user === null) {
            throw new CustomUserMessageAuthenticationException('Invalid token configuration');
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier())
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Allow request to continue to the controller
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => $exception->getMessageKey(),
        ], Response::HTTP_UNAUTHORIZED);
    }

    private function extractToken(Request $request): ?string
    {
        // Try X-Prompt-Token header first
        $token = $request->headers->get('X-Prompt-Token');

        if ($token !== null && $token !== '') {
            return $token;
        }

        // Try Authorization: Bearer header
        $authHeader = $request->headers->get('Authorization', '');

        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }
}
