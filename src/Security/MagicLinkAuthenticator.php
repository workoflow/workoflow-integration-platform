<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Organisation;
use App\Repository\UserRepository;
use App\Repository\OrganisationRepository;
use App\Service\MagicLinkService;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Psr\Log\LoggerInterface;

class MagicLinkAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UserRepository $userRepository,
        private OrganisationRepository $organisationRepository,
        private EntityManagerInterface $entityManager,
        private MagicLinkService $magicLinkService,
        private AuditLogService $auditLogService,
        private RouterInterface $router,
        private LoggerInterface $logger
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_magic_link' 
            && $request->query->has('token');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->query->get('token');
        
        // Validate token
        $payload = $this->magicLinkService->validateToken($token);
        
        if (!$payload) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired magic link');
        }

        $name = $payload['name'];
        $orgUuid = $payload['org_uuid'];

        // Generate a generic email from the name (without org UUID)
        $sanitizedName = strtolower(str_replace(' ', '.', $name));
        $generatedEmail = $sanitizedName . '@local.local';

        // Find or create organisation
        $organisation = $this->organisationRepository->findOneBy(['uuid' => $orgUuid]);
        
        if (!$organisation) {
            // Create new organisation if it doesn't exist
            $organisation = new Organisation();
            $organisation->setUuid($orgUuid);
            $organisation->setName('Organisation ' . substr($orgUuid, 0, 8)); // Default name
            
            $this->entityManager->persist($organisation);
            $this->entityManager->flush();
            
            $this->logger->info('Created new organisation via magic link', [
                'org_uuid' => $orgUuid,
                'name' => $organisation->getName()
            ]);
        }

        return new SelfValidatingPassport(
            new UserBadge($generatedEmail, function ($userIdentifier) use ($name, $organisation, $request) {
                $user = $this->userRepository->findOneBy(['email' => $userIdentifier]);
                
                if (!$user) {
                    // Create new user
                    $user = new User();
                    $user->setEmail($userIdentifier);  // Generated email
                    $user->setName($name);  // Actual name from token
                    $user->setRoles([User::ROLE_USER, User::ROLE_MEMBER]);
                    $user->addOrganisation($organisation);
                    
                    $this->entityManager->persist($user);
                    $this->entityManager->flush();
                    
                    $this->logger->info('Created new user via magic link', [
                        'email' => $userIdentifier,
                        'name' => $name,
                        'organisation' => $organisation->getName()
                    ]);

                    // Log user creation
                    $this->auditLogService->log(
                        'user.created_via_magic_link',
                        $user,
                        [
                            'email' => $userIdentifier,
                            'name' => $name,
                            'organisation_id' => $organisation->getId(),
                            'organisation_name' => $organisation->getName()
                        ]
                    );
                } else {
                    // Add organisation if not already associated
                    if (!$user->getOrganisations()->contains($organisation)) {
                        $user->addOrganisation($organisation);
                        
                        // Ensure user has member role if not admin
                        if (!$user->isAdmin()) {
                            $user->setRoles([User::ROLE_USER, User::ROLE_MEMBER]);
                        }
                        
                        $this->entityManager->flush();
                        
                        $this->logger->info('Added user to organisation via magic link', [
                            'email' => $userIdentifier,
                            'name' => $user->getName(),
                            'organisation' => $organisation->getName()
                        ]);

                        // Log organisation addition
                        $this->auditLogService->log(
                            'user.organisation_added_via_magic_link',
                            $user,
                            [
                                'organisation_id' => $organisation->getId(),
                                'organisation_name' => $organisation->getName()
                            ]
                        );
                    }
                }

                // Store current organisation in session for context
                if ($request->hasSession()) {
                    $request->getSession()->set('current_organisation_id', $organisation->getId());
                }

                // Log successful authentication
                $this->auditLogService->log(
                    'user.authenticated_via_magic_link',
                    $user,
                    [
                        'organisation_id' => $organisation->getId(),
                        'organisation_name' => $organisation->getName()
                    ]
                );

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Store success message in session
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'Successfully logged in via magic link');
        }

        // Redirect to dashboard or originally requested page
        $targetPath = $request->getSession()->get('_security.' . $firewallName . '.target_path');
        
        if ($targetPath) {
            $request->getSession()->remove('_security.' . $firewallName . '.target_path');
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('error', 
                $exception->getMessage() ?: 'Magic link authentication failed'
            );
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }

    private function extractNameFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        $localPart = $parts[0];
        
        // Replace common separators with spaces and capitalize
        $name = str_replace(['.', '_', '-'], ' ', $localPart);
        return ucwords($name);
    }
}