<?php

namespace App\Service;

use App\Entity\{AuditEntry, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** CLI access is an OS-level administrative boundary; actor labels identify its operator. */
final class AccountManager
{
    public function __construct(private EntityManagerInterface $em) {}
    public function create(string $email, string $role, #[\SensitiveParameter] string $hash, string $actor): User
    {
        return $this->em->wrapInTransaction(function () use ($email, $role, $hash, $actor): User {
            $user = new User($email);
            $user->changeRole($role);
            $user->changePassword($hash);
            $this->em->persist($user);
            $this->em->flush();
            $this->em->persist(new AuditEntry('cli:'.$actor, 'User', $user->getId(), 'account_create', ['email' => ['to' => $user->getEmail()], 'role' => ['to' => $role]]));
            return $user;
        });
    }
    public function change(User $user, string $operation, string $actor, #[\SensitiveParameter] ?string $value = null): void
    {
        $this->em->wrapInTransaction(function () use ($user, $operation, $actor, $value): void {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $changes = match ($operation) {
                'role' => ['role' => ['from' => $user->getRole(), 'to' => $value]],
                'disable' => ['enabled' => ['from' => $user->isEnabled(), 'to' => false]],
                'password-reset', 'mfa-reset' => ['credentials' => ['changed' => true]],
                default => throw new \InvalidArgumentException('Unknown account operation.'),
            };
            match ($operation) {
                'role' => $user->changeRole($value ?? ''),
                'disable' => $user->disable(),
                'password-reset' => $user->changePassword($value ?? throw new \InvalidArgumentException('Password required.')),
                'mfa-reset' => $user->resetMfa(),
            };
            $this->em->persist(new AuditEntry('cli:'.$actor, 'User', $user->getId(), 'account_'.$operation, $changes));
        });
    }
}
