<?php

namespace App\Command;

use App\Entity\{Project, ProjectEnvironment, Server};
use App\Service\InventoryWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:demo', description: 'Add fictional local/test inventory without purging existing data or creating accounts.')]
final class DemoCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private InventoryWriter $writer, #[Autowire('%kernel.environment%')] private string $environment) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!in_array($this->environment, ['dev', 'test'], true)) { $io->error('Demo fixtures are only available in dev/test.'); return Command::FAILURE; }
        // Refuse collisions instead of changing existing records, and never purge.
        foreach ([[Server::class, 'Demo North'], [Server::class, 'Demo South'], [Project::class, 'Demo Civic Portal'], [Project::class, 'Demo Legacy Register'], [Project::class, 'Demo Unconfigured']] as [$class, $name]) {
            if ($this->em->getRepository($class)->count(['name' => $name])) { $io->error('Demo names already exist. No records were changed.'); return Command::FAILURE; }
        }
        $this->em->wrapInTransaction(function (): void {
            $servers = [];
            foreach (['North', 'South'] as $index => $name) {
                $server = new Server();
                $server->name = 'Demo '.$name;
                $server->hostname = 'demo-'.strtolower($name).'.example.invalid';
                $server->sshUsername = 'inventory-demo';
                $this->writer->save($server, 'cli:demo-fixtures');
                $servers[] = $server;
            }
            foreach (['Civic Portal' => ['stage', 'prod'], 'Legacy Register' => ['prod'], 'Unconfigured' => []] as $name => $kinds) {
                $project = new Project();
                $project->name = 'Demo '.$name;
                $this->writer->save($project, 'cli:demo-fixtures');
                foreach ($kinds as $kind) {
                    $environment = new ProjectEnvironment();
                    $environment->project = $project;
                    $environment->server = $servers[$kind === 'stage' ? 0 : 1];
                    $environment->kind = $kind;
                    $environment->domains = strtolower(str_replace(' ', '-', $name)).'-'.$kind.'.example.invalid';
                    $environment->deploymentDescription = 'Fictional example. Current deployment is unverified.';
                    $environment->backupDescription = 'Unknown; no backup or restore has been verified.';
                    $environment->tlsDescription = 'Unknown; no certificate management has been verified.';
                    $this->writer->save($environment, 'cli:demo-fixtures');
                }
            }
        });
        $io->success('Added two fictional servers, three projects and three environments. No accounts created.');
        return Command::SUCCESS;
    }
}
