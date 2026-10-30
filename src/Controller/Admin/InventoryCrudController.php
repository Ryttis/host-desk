<?php

namespace App\Controller\Admin;

use App\Entity\{Project, ProjectEnvironment, Server, User};
use App\Service\InventoryWriter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud, KeyValueStore};
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\Response;

abstract class InventoryCrudController extends AbstractCrudController
{
    public function __construct(private InventoryWriter $writer) {}
    public function configureActions(Actions $actions): Actions
    {
        $archive = Action::new('archive', 'Archive', 'fa fa-box-archive')
            ->linkToRoute('inventory_archive', static fn (object $entity) => [
                'type' => match (true) { $entity instanceof Server => 'server', $entity instanceof Project => 'project', default => 'environment' },
                'id' => $entity->getId(),
            ])->displayIf(static fn (object $entity) => !$entity->isArchived());
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $archive)->add(Crud::PAGE_DETAIL, $archive)
            ->disable(Action::DELETE, Action::BATCH_DELETE)
            ->setPermission(Action::NEW, 'ROLE_ADMIN')->setPermission(Action::EDIT, 'ROLE_ADMIN')->setPermission('archive', 'ROLE_ADMIN')
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $action) => $action->displayIf(static fn (object $entity) => !$entity->isArchived()));
    }
    public function edit(AdminContext $context): Response|KeyValueStore
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if ($context->getRequest()->isXmlHttpRequest()) {
            throw $this->createAccessDeniedException('Use the normal inventory forms.');
        }
        if ($context->getEntity()->getInstance()->isArchived()) {
            throw $this->createAccessDeniedException('Archived records cannot be edited.');
        }
        return parent::edit($context);
    }
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->writer->save($entityInstance, $this->actor());
    }
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->writer->save($entityInstance, $this->actor());
    }
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        throw $this->createAccessDeniedException('Inventory must be archived.');
    }
    private function actor(): string
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isMfaConfirmed()) { throw $this->createAccessDeniedException(); }
        return 'user:'.$user->getId().' '.$user->getUserIdentifier();
    }
}
