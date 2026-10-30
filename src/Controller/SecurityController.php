<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\TotpCipher;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        return $this->render('security/login.html.twig', ['error' => $auth->getLastAuthenticationError(), 'last_username' => $auth->getLastUsername()]);
    }
    #[Route('/logout', name: 'logout')]
    public function logout(): never { throw new \LogicException('Handled by the firewall.'); }

    #[Route('/enroll', name: 'enroll', methods: ['GET', 'POST'])]
    public function enroll(Request $request, EntityManagerInterface $em, TotpCipher $cipher, TotpAuthenticatorInterface $totp,
        #[Autowire(service: 'limiter.enrollment')] RateLimiterFactory $limiter,
        TokenStorageInterface $tokens): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        if ($user->isMfaConfirmed()) { return $this->redirectToRoute('admin'); }
        if ($user->getTotpCiphertext() === null) {
            $secret = $totp->generateSecret();
            $user->beginEnrollment($cipher->encrypt($secret), $secret);
            $em->flush();
        }
        $secret = $cipher->decrypt($user->getTotpCiphertext());
        $user->unlockTotp($secret);
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('enroll', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            if (!$limiter->create((string) $user->getId())->consume()->isAccepted()) {
                $error = 'Too many attempts. Try again in 15 minutes.';
                $status = 429;
            } elseif (!$totp->checkCode($user, $request->request->getString('code'))) {
                $error = 'Invalid code. Check your authenticator and device clock.';
                $status = 422;
            } else {
                $user->confirmMfa();
                $em->flush();
                $tokens->setToken(null);
                $request->getSession()->invalidate();
                return $this->redirectToRoute('login', ['enrolled' => 1]);
            }
        }
        return $this->render('security/enroll.html.twig', ['secret' => $secret, 'error' => $error], new Response(status: $status));
    }
}
