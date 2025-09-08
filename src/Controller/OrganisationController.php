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
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);
        
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
    public function members(Request $request, UserRepository $userRepository): Response
    {
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);
        
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
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);
        
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

    #[Route('/switch/{id}', name: 'app_organisation_switch')]
    public function switch(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        $user = $this->getUser();
        
        // Check if user has access to this organisation
        $hasAccess = false;
        $targetOrganisation = null;
        foreach ($user->getOrganisations() as $org) {
            if ($org->getId() === $id) {
                $hasAccess = true;
                $targetOrganisation = $org;
                break;
            }
        }
        
        if (!$hasAccess || !$targetOrganisation) {
            $this->addFlash('error', 'You do not have access to this organisation');
            return $this->redirectToRoute('app_dashboard');
        }
        
        // Store the selected organisation ID in session
        $session = $request->getSession();
        $session->set('current_organisation_id', $id);
        
        $auditLogService->log(
            'organisation.switched',
            $user,
            ['organisation_id' => $id, 'organisation_name' => $targetOrganisation->getName()]
        );
        
        $this->addFlash('success', 'Switched to organisation: ' . $targetOrganisation->getName());
        
        // Redirect to the referer or dashboard
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }
        
        return $this->redirectToRoute('app_dashboard');
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
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);
        
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