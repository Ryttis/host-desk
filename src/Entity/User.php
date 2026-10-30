<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Totp\{TwoFactorInterface, TotpConfiguration, TotpConfigurationInterface};
use Symfony\Component\Security\Core\User\{UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface};

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, TwoFactorInterface
{
    public const ROLES = ['Viewer' => 'ROLE_VIEWER', 'Operator' => 'ROLE_OPERATOR', 'Admin' => 'ROLE_ADMIN'];

    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 180)]
    private string $email;
    #[ORM\Column]
    private string $password = '';
    #[ORM\Column(length: 20)]
    private string $role = 'ROLE_VIEWER';
    #[ORM\Column]
    private bool $enabled = true;
    #[ORM\Column]
    private int $sessionVersion = 1;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totpCiphertext = null;
    #[ORM\Column]
    private bool $mfaConfirmed = false;
    // Plaintext exists only in request memory and is never serialized into sessions.
    private ?string $totpSecret = null;

    public function __construct(string $email) { $this->email = strtolower(trim($email)); }
    public function getId(): ?int { return $this->id; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getEmail(): string { return $this->email; }
    public function getPassword(): string { return $this->password; }
    public function getRoles(): array { return [$this->role]; }
    public function getRole(): string { return $this->role; }
    public function isEnabled(): bool { return $this->enabled; }
    public function isMfaConfirmed(): bool { return $this->mfaConfirmed; }
    public function getSessionVersion(): int { return $this->sessionVersion; }
    public function getTotpCiphertext(): ?string { return $this->totpCiphertext; }
    public function eraseCredentials(): void {}
    public function __toString(): string { return $this->email; }

    public function changeRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Choose Viewer, Operator or Admin.');
        }
        $this->role = $role;
        ++$this->sessionVersion;
    }
    public function changePassword(#[\SensitiveParameter] string $hash): void
    {
        $this->password = $hash;
        ++$this->sessionVersion;
    }
    public function disable(): void { $this->enabled = false; ++$this->sessionVersion; }
    public function resetMfa(): void
    {
        $this->totpCiphertext = $this->totpSecret = null;
        $this->mfaConfirmed = false;
        ++$this->sessionVersion;
    }
    public function beginEnrollment(#[\SensitiveParameter] string $ciphertext, #[\SensitiveParameter] string $secret): void
    {
        if ($this->mfaConfirmed) {
            throw new \LogicException('MFA is already enrolled.');
        }
        $this->totpCiphertext = $ciphertext;
        $this->totpSecret = $secret;
    }
    public function unlockTotp(#[\SensitiveParameter] ?string $secret): void { $this->totpSecret = $secret; }
    public function confirmMfa(): void
    {
        if ($this->totpSecret === null || $this->totpCiphertext === null) {
            throw new \LogicException('Enrollment has not started.');
        }
        $this->mfaConfirmed = true;
        ++$this->sessionVersion;
    }
    public function isTotpAuthenticationEnabled(): bool { return $this->mfaConfirmed; }
    public function getTotpAuthenticationUsername(): string { return $this->email; }
    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return $this->totpSecret === null ? null : new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && $user->id === $this->id && $user->enabled
            && $user->sessionVersion === $this->sessionVersion && $user->role === $this->role;
    }
    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'role' => $this->role,
            'enabled' => $this->enabled, 'sessionVersion' => $this->sessionVersion, 'mfaConfirmed' => $this->mfaConfirmed];
    }
    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) { $this->$key = $value; }
    }
}
