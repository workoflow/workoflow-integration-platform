<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/organisation')]
#[IsGranted('ROLE_USER')]
class OrganisationController extends AbstractController
{
    #[Route('/settings', name: 'app_organisation_settings')]
    #[IsGranted('ROLE_ADMIN')]
    public function settings(
        Request $request,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        $user = $this->getUser();
        $organisation = $user->getOrganisation();
        
        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            
            if ($name && $name !== $organisation->getName()) {
                $oldName = $organisation->getName();
                $organisation->setName($name);
                $em->flush();
                
                $auditLogService->log(
                    'organisation.updated',
                    $user,
                    ['old_name' => $oldName, 'new_name' => $name]
                );
                
                $this->addFlash('success', 'organisation.updated.success');
                return $this->redirectToRoute('app_organisation_settings');
            }
        }

        return $this->render('organisation/settings.html.twig', [
            'organisation' => $organisation,
        ]);
    }

    #[Route('/members', name: 'app_organisation_members')]
    public function members(UserRepository $userRepository): Response
    {
        $user = $this->getUser();
        $organisation = $user->getOrganisation();
        
        if (!$organisation) {
            return $this->redirectToRoute('app_organisation_create');
        }

        $members = $userRepository->findByOrganisation($organisation->getId());

        return $this->render('organisation/members.html.twig', [
            'organisation' => $organisation,
            'members' => $members,
        ]);
    }

    #[Route('/members/{id}/role', name: 'app_organisation_member_role', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateMemberRole(
        User $member,
        Request $request,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        $user = $this->getUser();
        $organisation = $user->getOrganisation();
        
        if ($member->getOrganisation() !== $organisation) {
            throw $this->createAccessDeniedException();
        }

        if ($member->getId() === $user->getId()) {
            $this->addFlash('error', 'organisation.member.cannot_change_own_role');
            return $this->redirectToRoute('app_organisation_members');
        }

        $role = $request->request->get('role');
        if ($role === 'admin') {
            $member->setRoles([User::ROLE_USER, User::ROLE_ADMIN]);
        } else {
            $member->setRoles([User::ROLE_USER, User::ROLE_MEMBER]);
        }
        
        $em->flush();
        
        $auditLogService->log(
            'organisation.member.role_updated',
            $user,
            ['member_id' => $member->getId(), 'new_role' => $role]
        );
        
        $this->addFlash('success', 'organisation.member.role_updated');
        return $this->redirectToRoute('app_organisation_members');
    }

    #[Route('/invite', name: 'app_organisation_invite', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function invite(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        $user = $this->getUser();
        $organisation = $user->getOrganisation();
        
        $email = $request->request->get('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'organisation.invite.invalid_email');
            return $this->redirectToRoute('app_organisation_members');
        }

        $existingUser = $userRepository->findOneBy(['email' => $email]);
        if ($existingUser) {
            if ($existingUser->getOrganisation()) {
                $this->addFlash('error', 'organisation.invite.user_has_organisation');
            } else {
                $existingUser->setOrganisation($organisation);
                $existingUser->setRoles([User::ROLE_USER, User::ROLE_MEMBER]);
                $em->flush();
                
                $auditLogService->log(
                    'organisation.member.added',
                    $user,
                    ['invited_email' => $email]
                );
                
                $this->addFlash('success', 'organisation.invite.user_added');
            }
        } else {
            $this->addFlash('info', 'organisation.invite.user_not_found');
        }

        return $this->redirectToRoute('app_organisation_members');
    }
}