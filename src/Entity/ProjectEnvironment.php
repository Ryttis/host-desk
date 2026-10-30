<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_project_kind', columns: ['project_id', 'kind'])]
#[ORM\Index(name: 'idx_environment_project', columns: ['project_id'])]
#[ORM\Index(name: 'idx_environment_server', columns: ['server_id'])]
#[UniqueEntity(fields: ['project', 'kind'], message: 'This project already has that environment kind (including archived environments).')]
class ProjectEnvironment
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'environments'), ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    public ?Project $project = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    public ?Server $server = null;
    #[ORM\Column(length: 5)]
    #[Assert\Choice(choices: ['stage', 'prod'])]
    public string $kind = 'stage';
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank, Assert\Length(max: 4000)]
    public string $domains = '';
    #[ORM\Column(length: 1024, nullable: true)]
    #[Assert\Length(max: 1024), Assert\Regex(pattern: '#^/[^\x00\r\n]*$#', message: 'Use an absolute path, or leave it empty when unknown.')]
    public ?string $applicationPath = null;
    #[ORM\Column(length: 1024, nullable: true)]
    #[Assert\Length(max: 1024), Assert\Regex(pattern: '#^/[^\x00\r\n]*$#', message: 'Use an absolute path, or leave it empty when unknown.')]
    public ?string $publicPath = null;
    #[ORM\Column(type: 'text')]
    #[Assert\Length(max: 5000)]
    public string $deploymentDescription = '';
    #[ORM\Column(type: 'text')]
    #[Assert\Length(max: 5000)]
    public string $backupDescription = '';
    #[ORM\Column(type: 'text')]
    #[Assert\Length(max: 5000)]
    public string $tlsDescription = '';
    #[ORM\Column]
    private bool $archived = false;

    public function getId(): ?int { return $this->id; }
    public function isArchived(): bool { return $this->archived; }
    public function archive(): void { $this->archived = true; }
    #[Assert\Callback]
    public function validReferences(ExecutionContextInterface $context): void
    {
        if (!$this->archived) {
            foreach (['server', 'project'] as $field) {
                if ($this->$field?->isArchived()) {
                    $context->buildViolation('An active environment cannot reference an archived parent.')->atPath($field)->addViolation();
                }
            }
        }
        foreach (preg_split('/[\s,]+/', trim($this->domains), -1, PREG_SPLIT_NO_EMPTY) as $domain) {
            if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($domain, '.')) {
                $context->buildViolation('Enter explicit domain names separated by commas or newlines; no URLs or wildcards.')->atPath('domains')->addViolation();
                break;
            }
        }
    }
    public function __toString(): string { return ($this->project?->name ?? 'Project').' / '.$this->kind.($this->archived ? ' (Archived)' : ''); }
}
