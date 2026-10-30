<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\{RedirectResponse, Response};
use Symfony\Component\HttpKernel\Event\{ControllerEvent, ResponseEvent};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EnrollmentGate
{
    public function __construct(private Security $security, private UrlGeneratorInterface $urls) {}

    #[AsEventListener(event: 'kernel.controller', priority: 20)]
    public function restrict(ControllerEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isMfaConfirmed()) { return; }
        if (in_array($event->getRequest()->attributes->get('_route'), ['enroll', 'logout'], true)) { return; }
        $event->setController(fn () => new RedirectResponse($this->urls->generate('enroll')));
    }

    #[AsEventListener(event: 'kernel.response')]
    public function noStore(ResponseEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/assets/')) { return; }
        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
    }
}
