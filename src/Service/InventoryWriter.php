<?php

namespace App\Service;

use App\Entity\{AuditEntry, Project, ProjectEnvironment, Server};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** All inventory mutations enter here, including explicit local fixtures. */
final class InventoryWriter
{
    public function __construct(private EntityManagerInterface $em, private ValidatorInterface $validator) {}

    public function save(Server|Project|ProjectEnvironment $entity, string $actor): void
    {
        $this->em->wrapInTransaction(function () use ($entity, $actor): void {
            $new = $entity->getId() === null;
            $before = $new ? [] : $this->em->getUnitOfWork()->getOriginalEntityData($entity);
            if (($before['archived'] ?? false) || $entity->isArchived()) {
                throw new \DomainException('Archived records cannot be edited. Use the archive action to archive an active record.');
            }
            if (!$new) {
                // Preserve submitted changes while reading the current row under lock.
                // A stale form must not edit an archived row or audit stale before-values.
                $submitted = [];
                foreach ($this->fields($entity) as $field) {
                    if ($entity->$field !== ($before[$field] ?? null)) { $submitted[$field] = $entity->$field; }
                }
                $this->em->refresh($entity, LockMode::PESSIMISTIC_WRITE);
                if ($entity->isArchived()) { throw new \DomainException('Archived records cannot be edited.'); }
                $before = $this->em->getUnitOfWork()->getOriginalEntityData($entity);
                foreach ($submitted as $field => $value) { $entity->$field = $value; }
            }
            if ($entity instanceof ProjectEnvironment) {
                // Same parent locks as archive: a concurrent archive cannot slip past this check.
                foreach ([$entity->server, $entity->project] as $parent) {
                    if ($parent?->getId()) { $this->em->refresh($parent, LockMode::PESSIMISTIC_WRITE); }
                }
            }
            $errors = $this->validator->validate($entity);
            if (count($errors)) { throw new ValidationFailedException($entity, $errors); }
            $changes = $this->changes($entity, $before, $new);
            $this->em->persist($entity);
            $this->em->flush();
            if ($new || $changes !== []) {
                $this->em->persist(new AuditEntry($actor, (new \ReflectionClass($entity))->getShortName(), $entity->getId(), $new ? 'create' : 'update', $changes));
            }
        });
    }
    public function archive(Server|Project|ProjectEnvironment $entity, string $actor): void
    {
        $this->em->wrapInTransaction(function () use ($entity, $actor): void {
            $this->em->refresh($entity, LockMode::PESSIMISTIC_WRITE);
            if ($entity->isArchived()) { throw new \DomainException('This record is already archived.'); }
            if ($entity instanceof Server || $entity instanceof Project) {
                $field = $entity instanceof Server ? 'server' : 'project';
                if ($this->em->getRepository(ProjectEnvironment::class)->count([$field => $entity, 'archived' => false]) > 0) {
                    throw new \DomainException('Archive active environments before archiving their project or server.');
                }
            }
            $entity->archive();
            $this->em->persist(new AuditEntry($actor, (new \ReflectionClass($entity))->getShortName(), $entity->getId(), 'archive', ['archived' => ['from' => false, 'to' => true]]));
        });
    }
    private function fields(Server|Project|ProjectEnvironment $entity): array
    {
        return match (true) {
            $entity instanceof Server => ['name', 'hostname', 'sshPort', 'sshUsername', 'credentialReference'],
            $entity instanceof Project => ['name', 'repositoryUrl'],
            default => ['project', 'server', 'kind', 'domains', 'applicationPath', 'publicPath', 'deploymentDescription', 'backupDescription', 'tlsDescription'],
        };
    }
    private function changes(Server|Project|ProjectEnvironment $entity, array $before, bool $new): array
    {
        $changes = [];
        foreach ($this->fields($entity) as $field) {
            $old = $before[$field] ?? null;
            $value = $entity->$field;
            if ($old instanceof Server || $old instanceof Project) { $old = $old->getId(); }
            if ($value instanceof Server || $value instanceof Project) { $value = $value->getId(); }
            if ($new || $old !== $value) {
                // References and free-form operational notes can contain sensitive pasted text.
                // Record that those fields changed, never their contents.
                $changes[$field] = $field === 'credentialReference' || str_ends_with($field, 'Description')
                    ? ['changed' => true] : ['from' => $old, 'to' => $value];
            }
        }
        return $changes;
    }
}
