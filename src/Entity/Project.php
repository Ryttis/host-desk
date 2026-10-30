<?php

namespace App\Entity;

use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
class Project
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank, Assert\Length(max: 120)]
    public string $name = '';
    #[ORM\Column(length: 2048, nullable: true)]
    #[Assert\Url(protocols: ['https', 'http']), Assert\Length(max: 2048)]
    public ?string $repositoryUrl = null;
    #[ORM\Column]
    private bool $archived = false;
    /** @var Collection<int, ProjectEnvironment> */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectEnvironment::class)]
    private Collection $environments;

    public function __construct() { $this->environments = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function isArchived(): bool { return $this->archived; }
    public function archive(): void { $this->archived = true; }
    public function getEnvironments(): Collection { return $this->environments; }
    public function getConfigurationStatus(): string
    {
        $active = $this->environments->filter(static fn (ProjectEnvironment $e) => !$e->isArchived());
        return $active->isEmpty() ? 'Not configured' : implode(', ', $active->map(static fn (ProjectEnvironment $e) => ucfirst($e->kind))->toArray());
    }
    #[Assert\Callback]
    public function safeRepository(ExecutionContextInterface $context): void
    {
        if (!$this->repositoryUrl) { return; }
        $parts = parse_url($this->repositoryUrl);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            $context->buildViolation('Use a repository URL without credentials, query parameters or fragments.')->atPath('repositoryUrl')->addViolation();
        }
    }
    public function __toString(): string { return $this->name.($this->archived ? ' (Archived)' : ''); }
}
