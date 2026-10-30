<?php

namespace App\Controller\Admin;

use App\Entity\Project;
use EasyCorp\Bundle\EasyAdminBundle\Field\{BooleanField, TextField, UrlField};

final class ProjectCrudController extends InventoryCrudController
{
    public static function getEntityFqcn(): string { return Project::class; }
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield UrlField::new('repositoryUrl')->setRequired(false)->setHelp('HTTP(S) repository URL without credentials, query parameters or fragments.');
        yield TextField::new('configurationStatus', 'Environments')->hideOnForm();
        yield BooleanField::new('archived')->renderAsSwitch(false)->hideOnForm();
    }
}
