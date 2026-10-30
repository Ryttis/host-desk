<?php

namespace App\Controller\Admin;

use App\Entity\{AuditEntry, Project, ProjectEnvironment, Server};
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Dashboard, MenuItem};
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private EntityManagerInterface $em, private AdminUrlGenerator $urls) {}
    public function index(): Response
    {
        $links = [];
        foreach (['Servers' => [Server::class, ServerCrudController::class], 'Projects' => [Project::class, ProjectCrudController::class], 'Environments' => [ProjectEnvironment::class, ProjectEnvironmentCrudController::class]] as $label => [$entity, $controller]) {
            $links[] = ['label' => $label, 'active' => $this->em->getRepository($entity)->count(['archived' => false]),
                'url' => $this->urls->unsetAll()->setController($controller)->setAction('index')->generateUrl()];
        }
        return $this->render('admin/overview.html.twig', ['links' => $links]);
    }
    #[Route('/', name: 'home')]
    public function home(): Response { return $this->redirectToRoute('admin'); }
    public function configureDashboard(): Dashboard { return Dashboard::new()->setTitle('HostDesk')->setLocales(['en']); }
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Overview', 'fa fa-house');
        yield MenuItem::linkToCrud('Servers', 'fa fa-server', Server::class);
        yield MenuItem::linkToCrud('Projects', 'fa fa-folder', Project::class);
        yield MenuItem::linkToCrud('Environments', 'fa fa-layer-group', ProjectEnvironment::class);
        yield MenuItem::linkToCrud('Audit history', 'fa fa-list', AuditEntry::class)->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToLogout('Sign out', 'fa fa-sign-out');
    }
}
