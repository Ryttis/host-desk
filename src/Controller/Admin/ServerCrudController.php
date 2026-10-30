<?php

namespace App\Controller\Admin;

use App\Entity\Server;
use EasyCorp\Bundle\EasyAdminBundle\Field\{BooleanField, IntegerField, TextField};

final class ServerCrudController extends InventoryCrudController
{
    public static function getEntityFqcn(): string { return Server::class; }
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield TextField::new('hostname', 'Hostname / IP');
        yield IntegerField::new('sshPort', 'SSH port')->setHelp('Inventory metadata only; HostDesk does not connect.');
        yield TextField::new('sshUsername', 'SSH username');
        yield TextField::new('credentialReference')->setRequired(false)->hideOnIndex()->setHelp('A reference to an external vault entry, never a credential value.');
        yield BooleanField::new('archived')->renderAsSwitch(false)->hideOnForm();
    }
}
