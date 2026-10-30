<?php

namespace App\Controller\Admin;

use App\Entity\ProjectEnvironment;
use EasyCorp\Bundle\EasyAdminBundle\Field\{AssociationField, BooleanField, ChoiceField, TextField, TextareaField};

final class ProjectEnvironmentCrudController extends InventoryCrudController
{
    public static function getEntityFqcn(): string { return ProjectEnvironment::class; }
    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('project');
        yield AssociationField::new('server');
        yield ChoiceField::new('kind')->setChoices(['Stage' => 'stage', 'Prod' => 'prod'])->renderAsBadges(['stage' => 'info', 'prod' => 'warning']);
        yield TextareaField::new('domains')->setHelp('Explicit domain names, one per line or separated by commas.');
        yield TextField::new('applicationPath')->setRequired(false)->hideOnIndex()->setHelp('Leave empty when unknown.');
        yield TextField::new('publicPath')->setRequired(false)->hideOnIndex()->setHelp('Leave empty when unknown.');
        yield TextareaField::new('deploymentDescription', 'Current deployment')->setRequired(false)->hideOnIndex()->setFormTypeOption('empty_data', '')->setHelp('Describe the current arrangement; never paste secrets.');
        yield TextareaField::new('backupDescription', 'Current backup')->setRequired(false)->hideOnIndex()->setFormTypeOption('empty_data', '');
        yield TextareaField::new('tlsDescription', 'Current TLS management')->setRequired(false)->hideOnIndex()->setFormTypeOption('empty_data', '');
        yield BooleanField::new('archived')->renderAsSwitch(false)->hideOnForm();
    }
}
