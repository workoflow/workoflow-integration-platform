<?php

namespace App\Controller;

use App\Entity\WaitlistEntry;
use App\Repository\WaitlistEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // If user is already authenticated, redirect to dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('home/index.html.twig');
    }

    #[Route('/waitlist/submit', name: 'app_waitlist_submit', methods: ['POST'])]
    public function submitWaitlist(
        Request $request,
        WaitlistEntryRepository $repository,
        ValidatorInterface $validator
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;

        if (!$email) {
            return $this->json(['success' => false, 'error' => 'Email is required'], 400);
        }

        // Check if email already exists
        $existing = $repository->findByEmail($email);
        if ($existing) {
            return $this->json(['success' => false, 'error' => 'This email is already on the waitlist'], 400);
        }

        // Create new waitlist entry
        $entry = new WaitlistEntry();
        $entry->setEmail($email);
        $entry->setIpAddress($request->getClientIp());

        // Validate the entry
        $errors = $validator->validate($entry);
        if (count($errors) > 0) {
            return $this->json(['success' => false, 'error' => 'Invalid email address'], 400);
        }

        // Save to database
        try {
            $repository->save($entry, true);
            return $this->json(['success' => true, 'message' => 'Successfully joined the waitlist!']);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'An error occurred. Please try again.'], 500);
        }
    }
}
