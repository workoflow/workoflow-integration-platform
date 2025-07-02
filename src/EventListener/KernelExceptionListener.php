<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;

class KernelExceptionListener implements EventSubscriberInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 2],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // Handle authentication exceptions that weren't caught by the entry point
        if ($exception instanceof InsufficientAuthenticationException) {
            $request = $event->getRequest();
            
            // Skip handling for public routes
            $route = $request->attributes->get('_route');
            if (in_array($route, ['app_home', 'app_login'])) {
                return;
            }
            
            // Store the target path in session
            if ($request->hasSession() && $targetPath = $request->getRequestUri()) {
                $request->getSession()->set('_security.main.target_path', $targetPath);
            }

            // Add flash message
            if ($request->hasSession()) {
                $request->getSession()->getFlashBag()->add(
                    'error',
                    'Please log in to access this page.'
                );
            }

            $response = new RedirectResponse($this->urlGenerator->generate('app_login'));
            $event->setResponse($response);
        }
    }
}