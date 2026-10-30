<?php

namespace App\Controller\Admin;

use App\Entity\AuditEntry;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\{CodeEditorField, DateTimeField, IntegerField, TextField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AuditEntryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return AuditEntry::class; }
    public function configureCrud(Crud $crud): Crud { return $crud->setDefaultSort(['id' => 'DESC']); }
    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('occurredAt')->setTimezone('UTC');
        yield TextField::new('actor');
        yield TextField::new('targetType');
        yield IntegerField::new('targetId');
        yield TextField::new('action');
        yield CodeEditorField::new('formattedChanges', 'Safe field changes')->setLanguage('javascript')->onlyOnDetail();
    }
}
