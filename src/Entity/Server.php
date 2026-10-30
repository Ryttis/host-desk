<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Server
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank, Assert\Length(max: 120)]
    public string $name = '';
    #[ORM\Column(length: 253)]
    #[Assert\NotBlank, Assert\Length(max: 253)]
    #[Assert\Regex(pattern: '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?|[0-9a-fA-F:]+)$/', message: 'Enter an explicit hostname or IP, without a URL, port or username.')]
    public string $hostname = '';
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 65535)]
    public int $sshPort = 22;
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank, Assert\Length(max: 64)]
    public string $sshUsername = '';
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    public ?string $credentialReference = null;
    #[ORM\Column]
    private bool $archived = false;

    public function getId(): ?int { return $this->id; }
    public function isArchived(): bool { return $this->archived; }
    public function archive(): void { $this->archived = true; }
    public function __toString(): string { return $this->name.($this->archived ? ' (Archived)' : ''); }
}
