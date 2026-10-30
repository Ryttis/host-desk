<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\{UnsupportedUserException, UserNotFoundException};
use Symfony\Component\Security\Core\User\{UserInterface, UserProviderInterface};

/** @implements UserProviderInterface<User> */
final class UserProvider implements UserProviderInterface
{
    public function __construct(private EntityManagerInterface $em, private TotpCipher $cipher) {}
    public function loadUserByIdentifier(string $identifier): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => strtolower(trim($identifier))]);
        if (!$user) { throw new UserNotFoundException(); }
        return $this->unlock($user);
    }
    public function refreshUser(UserInterface $user): User
    {
        if (!$user instanceof User) { throw new UnsupportedUserException(); }
        $sessionUser = clone $user;
        $fresh = $this->em->find(User::class, $user->getId());
        if (!$fresh) { throw new UserNotFoundException(); }
        $this->em->refresh($fresh);
        // Scheb's in-progress token is not an AbstractToken, so Symfony does not
        // perform its EquatableInterface check. Enforce revocation for every token.
        if (!$sessionUser->isEqualTo($fresh)) { throw new UserNotFoundException(); }
        return $this->unlock($fresh);
    }
    private function unlock(User $user): User
    {
        $user->unlockTotp($user->getTotpCiphertext() === null ? null : $this->cipher->decrypt($user->getTotpCiphertext()));
        return $user;
    }
    public function supportsClass(string $class): bool { return is_a($class, User::class, true); }
}
