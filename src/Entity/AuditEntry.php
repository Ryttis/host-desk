<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AuditEntry
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 255)]
    private string $actor;
    #[ORM\Column(length: 40)]
    private string $targetType;
    #[ORM\Column]
    private int $targetId;
    #[ORM\Column(length: 40)]
    private string $action;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;
    #[ORM\Column(type: 'json')]
    private array $changes;

    public function __construct(string $actor, string $targetType, int $targetId, string $action, array $changes)
    {
        $this->actor = $actor;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->action = $action;
        $this->changes = $changes;
        $this->occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    public function getId(): ?int { return $this->id; }
    public function getActor(): string { return $this->actor; }
    public function getTargetType(): string { return $this->targetType; }
    public function getTargetId(): int { return $this->targetId; }
    public function getAction(): string { return $this->action; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getChanges(): array { return $this->changes; }
    public function getFormattedChanges(): string { return json_encode($this->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
