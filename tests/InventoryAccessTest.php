<?php

namespace App\Tests;

use App\Controller\Admin\{AuditEntryCrudController, ProjectCrudController, ProjectEnvironmentCrudController, ServerCrudController};
use App\Entity\{AuditEntry, Project, ProjectEnvironment, Server, User};
use App\Security\TotpCipher;
use App\Service\{AccountManager, InventoryWriter};
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\DataProvider;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class InventoryAccessTest extends WebTestCase
{
    private KernelBrowser $browser;
    private string $password;
    private array $secrets = [];

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $em = $this->em();
        // Never purge a development or unrelated database.
        if ($em->getConnection()->getDatabase() !== 'hostdesk_test' || !$em->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            throw new \RuntimeException('Tests require the isolated PostgreSQL database named hostdesk_test.');
        }
        $em->getConnection()->executeStatement('TRUNCATE audit_entry, project_environment, project, server, app_user RESTART IDENTITY CASCADE');
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->password = bin2hex(random_bytes(20));
    }
    private function assertRedirectPath(string $expected): void
    {
        self::assertResponseRedirects();
        $location = $this->browser->getResponse()->headers->get('Location');
        $path = parse_url($location, PHP_URL_PATH);
        $query = parse_url($location, PHP_URL_QUERY);
        self::assertSame($expected, $path.($query === null ? '' : '?'.$query));
    }
    private function em(): EntityManagerInterface { return static::getContainer()->get(EntityManagerInterface::class); }
    private function account(string $role = 'ROLE_ADMIN', bool $confirmed = true): User
    {
        $email = strtolower(substr($role, 5)).'@example.invalid';
        $user = new User($email);
        $user->changeRole($role);
        $user->changePassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $this->password));
        if ($confirmed) {
            $secret = static::getContainer()->get(TotpAuthenticatorInterface::class)->generateSecret();
            $user->beginEnrollment(static::getContainer()->get(TotpCipher::class)->encrypt($secret), $secret);
            $user->confirmMfa();
            $this->secrets[$email] = $secret;
        }
        $this->em()->persist($user);
        $this->em()->flush();
        return $user;
    }
    private function passwordLogin(string $email): void
    {
        $this->browser->request('GET', '/login');
        $this->browser->submitForm('Sign in', ['_username' => $email, '_password' => $this->password]);
        self::assertResponseRedirects();
    }
    private function signIn(string $email): void
    {
        $this->passwordLogin($email);
        $this->browser->request('GET', '/2fa');
        $this->browser->submitForm('Verify', ['_auth_code' => TOTP::create($this->secrets[$email])->now()]);
        $this->assertRedirectPath('/admin');
        $this->browser->followRedirect();
        self::assertResponseIsSuccessful();
    }
    private function url(string $controller, string $action = 'index', ?int $id = null): string
    {
        $generator = static::getContainer()->get(AdminUrlGenerator::class)->unsetAll()->setController($controller)->setAction($action);
        if ($id !== null) { $generator->setEntityId($id); }
        return $generator->generateUrl();
    }

    public function testAnonymousAndIncompleteEnrollmentCannotAccessInventory(): void
    {
        $this->browser->request('GET', '/admin');
        $this->assertRedirectPath('/login');
        $user = $this->account(confirmed: false);
        $this->passwordLogin($user->getEmail());
        $this->browser->request('GET', '/admin');
        $this->assertRedirectPath('/enroll');
        $url = $this->url(ServerCrudController::class, 'new');
        $this->browser->request('POST', $url, ['Server' => ['name' => 'Forbidden']]);
        $this->assertRedirectPath('/enroll');
        self::assertSame(0, $this->em()->getRepository(Server::class)->count([]));
        $this->browser->request('GET', '/enroll');
        self::assertResponseIsSuccessful();
        $this->browser->submitForm('Confirm enrollment', ['code' => 'invalid']);
        self::assertResponseStatusCodeSame(422);
        $current = $this->em()->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]);
        self::assertFalse($current->isMfaConfirmed());
        $secret = static::getContainer()->get(TotpCipher::class)->decrypt($current->getTotpCiphertext());
        $this->browser->submitForm('Confirm enrollment', ['code' => TOTP::create($secret)->now()]);
        $this->assertRedirectPath('/login?enrolled=1');
        $this->browser->request('GET', '/admin');
        $this->assertRedirectPath('/login');
        $this->secrets[$user->getEmail()] = $secret;
        $this->signIn($user->getEmail());
    }

    public function testInvalidTotpFailsAndValidTotpGrantsAccess(): void
    {
        $user = $this->account();
        $this->passwordLogin($user->getEmail());
        $this->browser->request('GET', '/admin');
        $this->assertRedirectPath('/2fa');
        $this->browser->request('GET', '/2fa');
        $this->browser->submitForm('Verify', ['_auth_code' => 'invalid']);
        $this->assertRedirectPath('/2fa');
        $this->browser->followRedirect();
        $this->browser->submitForm('Verify', ['_auth_code' => TOTP::create($this->secrets[$user->getEmail()])->now()]);
        $this->assertRedirectPath('/admin');
        $this->browser->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testEnrollmentAttemptsAreLimited(): void
    {
        $user = $this->account(confirmed: false);
        $this->passwordLogin($user->getEmail());
        $this->browser->request('GET', '/enroll');
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->browser->submitForm('Confirm enrollment', ['code' => 'invalid']);
            self::assertResponseStatusCodeSame(422);
        }
        $this->browser->submitForm('Confirm enrollment', ['code' => 'invalid']);
        self::assertResponseStatusCodeSame(429);
        $this->browser->request('GET', '/admin');
        $this->assertRedirectPath('/enroll');
    }

    public static function readers(): iterable { yield ['ROLE_VIEWER']; yield ['ROLE_OPERATOR']; }
    #[DataProvider('readers')]
    public function testReadersCannotMutateThroughDirectUrlsOrPosts(string $role): void
    {
        $user = $this->account($role);
        $server = $this->server();
        $id = $server->getId();
        $this->signIn($user->getEmail());
        foreach ([ServerCrudController::class, ProjectCrudController::class, ProjectEnvironmentCrudController::class] as $controller) {
            $url = $this->url($controller, 'new');
            foreach (['GET', 'POST'] as $method) {
                $this->browser->request($method, $url);
                self::assertResponseStatusCodeSame(403);
            }
        }
        foreach (['GET', 'POST'] as $method) {
            $this->browser->request($method, $this->url(ServerCrudController::class, 'edit', $id));
            self::assertResponseStatusCodeSame(403);
            $this->browser->request($method, '/admin/archive/server/'.$id);
            self::assertResponseStatusCodeSame(403);
        }
        $this->browser->request('GET', $this->url(AuditEntryCrudController::class));
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->em()->find(Server::class, $id)->isArchived());
    }

    public function testAdminCrudArchiveAndAtomicSafeAudit(): void
    {
        $user = $this->account();
        $actor = 'user:'.$user->getId().' '.$user->getEmail();
        $this->signIn($user->getEmail());
        $this->browser->request('GET', $this->url(ServerCrudController::class, 'new'));
        $this->browser->submitForm('Create', ['Server[name]' => 'Fictional host', 'Server[hostname]' => 'host.example.invalid', 'Server[sshPort]' => '22', 'Server[sshUsername]' => 'operator', 'Server[credentialReference]' => 'vault-reference-not-for-audit']);
        self::assertResponseRedirects();
        $server = $this->em()->getRepository(Server::class)->findOneBy(['name' => 'Fictional host']);
        self::assertNotNull($server);
        $id = $server->getId();
        $this->browser->request('GET', $this->url(ServerCrudController::class, 'edit', $id));
        $this->browser->submitForm('Save changes', ['Server[name]' => 'Renamed host']);
        self::assertResponseRedirects();
        $this->browser->request('POST', '/admin/archive/server/'.$id);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/admin/archive/server/'.$id);
        $this->browser->submitForm('Archive');
        $this->assertRedirectPath('/admin');
        self::assertTrue($this->em()->find(Server::class, $id)->isArchived());
        $entries = $this->em()->getRepository(AuditEntry::class)->findBy(['targetType' => 'Server', 'targetId' => $id], ['id' => 'ASC']);
        self::assertSame(['create', 'update', 'archive'], array_map(fn (AuditEntry $a) => $a->getAction(), $entries));
        foreach ($entries as $entry) {
            self::assertSame($actor, $entry->getActor());
            self::assertStringNotContainsString('vault-reference-not-for-audit', $entry->getFormattedChanges());
        }
        self::assertSame(['from' => 'Fictional host', 'to' => 'Renamed host'], $entries[1]->getChanges()['name']);
        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        $rollback = new Server();
        $rollback->name = 'Rollback'; $rollback->hostname = 'rollback.example.invalid'; $rollback->sshUsername = 'operator';
        static::getContainer()->get(InventoryWriter::class)->save($rollback, $actor);
        $rollbackId = $rollback->getId();
        $connection->rollBack();
        $this->em()->clear();
        self::assertNull($this->em()->find(Server::class, $rollbackId));
        self::assertSame(0, $this->em()->getRepository(AuditEntry::class)->count(['targetType' => 'Server', 'targetId' => $rollbackId]));
    }

    private function server(): Server
    {
        $server = new Server(); $server->name = 'Test server'; $server->hostname = 'test.example.invalid'; $server->sshUsername = 'operator';
        static::getContainer()->get(InventoryWriter::class)->save($server, 'cli:test');
        return $server;
    }
    private function environment(): ProjectEnvironment
    {
        $server = $this->server();
        $project = new Project(); $project->name = 'Test project';
        $writer = static::getContainer()->get(InventoryWriter::class);
        $writer->save($project, 'cli:test');
        $env = new ProjectEnvironment(); $env->project = $project; $env->server = $server; $env->kind = 'prod'; $env->domains = 'test.example.invalid';
        $writer->save($env, 'cli:test');
        return $env;
    }
    public function testDuplicateKindIsRejectedByValidationAndDatabase(): void
    {
        $env = $this->environment();
        $duplicate = new ProjectEnvironment(); $duplicate->project = $env->project; $duplicate->server = $env->server; $duplicate->kind = 'prod'; $duplicate->domains = 'other.example.invalid';
        self::assertGreaterThan(0, count(static::getContainer()->get(ValidatorInterface::class)->validate($duplicate)));
        $this->em()->persist($duplicate);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }
    public static function parents(): iterable { yield ['server']; yield ['project']; }
    #[DataProvider('parents')]
    public function testActiveEnvironmentPreventsParentArchive(string $parent): void
    {
        $env = $this->environment();
        $count = $this->em()->getRepository(AuditEntry::class)->count([]);
        try { static::getContainer()->get(InventoryWriter::class)->archive($env->$parent, 'cli:test'); self::fail('Archive must be rejected.'); }
        catch (\DomainException) {
            self::assertSame($count, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM audit_entry'));
            self::assertFalse((bool) $this->em()->getConnection()->fetchOne('SELECT archived FROM '.$parent.' WHERE id = ?', [$env->$parent->getId()]));
        }
    }
    public function testArchivedParentCannotReceiveActiveEnvironment(): void
    {
        $server = $this->server();
        static::getContainer()->get(InventoryWriter::class)->archive($server, 'cli:test');
        $project = new Project(); $project->name = 'Empty project';
        static::getContainer()->get(InventoryWriter::class)->save($project, 'cli:test');
        self::assertSame('Not configured', $project->getConfigurationStatus());
        $env = new ProjectEnvironment(); $env->project = $project; $env->server = $server; $env->domains = 'test.example.invalid';
        self::assertGreaterThan(0, count(static::getContainer()->get(ValidatorInterface::class)->validate($env)));
    }
    public function testStaleEditCannotChangeAnArchivedRecord(): void
    {
        $server = $this->server();
        $other = new \Doctrine\ORM\EntityManager($this->em()->getConnection(), $this->em()->getConfiguration());
        $writer = new InventoryWriter($other, static::getContainer()->get(ValidatorInterface::class));
        $writer->archive($other->find(Server::class, $server->getId()), 'cli:other');
        $server->name = 'Stale edit';
        try {
            static::getContainer()->get(InventoryWriter::class)->save($server, 'cli:test');
            self::fail('A stale edit must not change an archived record.');
        } catch (\DomainException) {
            $connection = $this->em()->getConnection();
            self::assertSame('Test server', $connection->fetchOne('SELECT name FROM server WHERE id = ?', [$server->getId()]));
            self::assertSame(0, (int) $connection->fetchOne("SELECT count(*) FROM audit_entry WHERE action = 'update'"));
        }
    }
    public function testConcurrentEditAuditsCurrentValuesAndPreservesOtherFields(): void
    {
        $server = $this->server();
        $other = new \Doctrine\ORM\EntityManager($this->em()->getConnection(), $this->em()->getConfiguration());
        $concurrent = $other->find(Server::class, $server->getId());
        $concurrent->name = 'Concurrent name';
        $concurrent->sshPort = 2222;
        (new InventoryWriter($other, static::getContainer()->get(ValidatorInterface::class)))->save($concurrent, 'cli:other');
        $server->name = 'Submitted name';
        static::getContainer()->get(InventoryWriter::class)->save($server, 'cli:test');
        self::assertSame(2222, $server->sshPort);
        $entry = $this->em()->getRepository(AuditEntry::class)->findOneBy(['action' => 'update', 'actor' => 'cli:test']);
        self::assertSame(['name' => ['from' => 'Concurrent name', 'to' => 'Submitted name']], $entry->getChanges());
    }
    public function testAuditInsertFailureRollsBackInventoryMutation(): void
    {
        $server = $this->server();
        $server->name = 'Must roll back';
        try {
            // Actor length exceeds the PostgreSQL audit column limit, after the inventory flush.
            static::getContainer()->get(InventoryWriter::class)->save($server, str_repeat('x', 256));
            self::fail('Audit failure must abort the entire write.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            $connection = $this->em()->getConnection();
            self::assertSame('Test server', $connection->fetchOne('SELECT name FROM server WHERE id = ?', [$server->getId()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM audit_entry'));
        }
    }
    public function testAdminProjectAndEnvironmentFormsAndArchiveLifecycle(): void
    {
        $user = $this->account();
        $server = $this->server();
        $serverId = $server->getId();
        $this->signIn($user->getEmail());
        $this->browser->request('GET', $this->url(ProjectCrudController::class, 'new'));
        $this->browser->submitForm('Create', ['Project[name]' => 'Form project']);
        self::assertResponseRedirects();
        $project = $this->em()->getRepository(Project::class)->findOneBy(['name' => 'Form project']);
        self::assertSame('Not configured', $project->getConfigurationStatus());
        $projectId = $project->getId();
        $this->browser->request('GET', $this->url(ProjectEnvironmentCrudController::class, 'new'));
        self::assertResponseIsSuccessful();
        $this->browser->submitForm('Create', [
            'ProjectEnvironment[project]' => $projectId, 'ProjectEnvironment[server]' => $serverId,
            'ProjectEnvironment[kind]' => 'prod', 'ProjectEnvironment[domains]' => 'form.example.invalid',
            'ProjectEnvironment[deploymentDescription]' => 'Private note excluded from audit',
        ]);
        self::assertResponseRedirects();
        $environment = $this->em()->getRepository(ProjectEnvironment::class)->findOneBy(['project' => $projectId]);
        self::assertNotNull($environment);
        $environmentId = $environment->getId();
        self::assertNull($environment->applicationPath);
        self::assertSame('', $environment->backupDescription);
        $this->browser->request('GET', $this->url(ProjectEnvironmentCrudController::class, 'edit', $environmentId));
        $this->browser->submitForm('Save changes', ['ProjectEnvironment[domains]' => 'renamed.example.invalid']);
        self::assertResponseRedirects();
        $this->browser->request('GET', '/admin/archive/server/'.$serverId);
        $this->browser->submitForm('Archive');
        self::assertResponseStatusCodeSame(422);
        foreach (['environment' => $environmentId, 'server' => $serverId, 'project' => $projectId] as $type => $id) {
            $this->browser->request('GET', '/admin/archive/'.$type.'/'.$id);
            $this->browser->submitForm('Archive');
            $this->assertRedirectPath('/admin');
        }
        foreach ([ServerCrudController::class => $serverId, ProjectCrudController::class => $projectId, ProjectEnvironmentCrudController::class => $environmentId] as $controller => $id) {
            $this->browser->request('GET', $this->url($controller, 'edit', $id));
            self::assertResponseStatusCodeSame(403);
            $this->browser->request('POST', $this->url($controller, 'delete', $id));
            self::assertResponseStatusCodeSame(403);
            $this->browser->request('GET', $this->url($controller, 'detail', $id));
            self::assertResponseIsSuccessful();
        }
        $this->browser->request('GET', $this->url(AuditEntryCrudController::class));
        self::assertResponseIsSuccessful();
        $entry = $this->em()->getRepository(AuditEntry::class)->findOneBy(['targetType' => 'ProjectEnvironment', 'action' => 'create']);
        self::assertStringNotContainsString('Private note', $entry->getFormattedChanges());
        $this->browser->request('GET', $this->url(AuditEntryCrudController::class, 'detail', $entry->getId()));
        self::assertResponseIsSuccessful();
        $this->browser->request('POST', $this->url(AuditEntryCrudController::class, 'edit', $entry->getId()));
        self::assertResponseStatusCodeSame(403);
    }
    public function testPasswordAndTotpAttemptsAreThrottled(): void
    {
        $user = $this->account();
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->browser->request('GET', '/login');
            $this->browser->submitForm('Sign in', ['_username' => $user->getEmail(), '_password' => 'invalid']);
            $this->assertRedirectPath('/login');
        }
        $this->passwordLogin($user->getEmail());
        $this->assertRedirectPath('/login');
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->passwordLogin($user->getEmail());
        $this->browser->request('GET', '/2fa');
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->browser->submitForm('Verify', ['_auth_code' => 'invalid']);
            $this->assertRedirectPath('/2fa');
            $this->browser->followRedirect();
        }
        $this->browser->submitForm('Verify', ['_auth_code' => TOTP::create($this->secrets[$user->getEmail()])->now()]);
        $this->assertRedirectPath('/2fa');
        $this->browser->request('GET', '/admin');
        $this->assertRedirectPath('/2fa');
    }
    public function testLoginAndMfaRejectMissingCsrf(): void
    {
        $user = $this->account();
        $this->browser->request('POST', '/login', ['_username' => $user->getEmail(), '_password' => $this->password]);
        $this->assertRedirectPath('/login');
        $this->passwordLogin($user->getEmail());
        $this->browser->request('POST', '/2fa/check', ['_auth_code' => TOTP::create($this->secrets[$user->getEmail()])->now()]);
        $this->assertRedirectPath('/2fa');
        $this->browser->request('GET', '/admin');
        $this->assertRedirectPath('/2fa');
    }
    public function testAccountCommandCreatesAndDisablesWithoutExposingCredentials(): void
    {
        $command = new \Symfony\Component\Console\Tester\CommandTester(static::getContainer()->get(\App\Command\AccountCommand::class));
        $command->setInputs([$this->password, $this->password]);
        self::assertSame(0, $command->execute(['operation' => 'create', 'email' => 'cli@example.invalid', '--role' => 'Admin', '--actor' => 'test-operator']));
        self::assertStringNotContainsString($this->password, $command->getDisplay());
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'cli@example.invalid']);
        self::assertNotNull($user);
        self::assertFalse($user->isMfaConfirmed());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $this->password));
        $audit = $this->em()->getRepository(AuditEntry::class)->findOneBy(['action' => 'account_create']);
        self::assertStringNotContainsString($user->getPassword(), $audit->getFormattedChanges());
        self::assertSame(0, $command->execute(['operation' => 'disable', 'email' => $user->getEmail(), '--actor' => 'test-operator']));
        $this->passwordLogin($user->getEmail());
        $this->assertRedirectPath('/login');
    }
    public function testDemoCommandIsAuditedAndRefusesExistingNames(): void
    {
        $command = new \Symfony\Component\Console\Tester\CommandTester(static::getContainer()->get(\App\Command\DemoCommand::class));
        self::assertSame(0, $command->execute([]));
        self::assertSame(2, $this->em()->getRepository(Server::class)->count([]));
        self::assertSame(3, $this->em()->getRepository(Project::class)->count([]));
        self::assertSame(3, $this->em()->getRepository(ProjectEnvironment::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));
        self::assertSame(8, $this->em()->getRepository(AuditEntry::class)->count(['actor' => 'cli:demo-fixtures']));
        self::assertSame(1, $command->execute([]));
        self::assertSame(8, $this->em()->getRepository(AuditEntry::class)->count([]));
    }
    public static function revocations(): iterable { yield ['disable']; yield ['password-reset']; yield ['mfa-reset']; yield ['role']; }
    #[DataProvider('revocations')]
    public function testAdministrativeChangesRevokeExistingSession(string $operation): void
    {
        $user = $this->account();
        $id = $user->getId();
        $this->signIn($user->getEmail());
        $current = $this->em()->find(User::class, $id);
        $value = match ($operation) {
            'password-reset' => static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($current, bin2hex(random_bytes(20))),
            'role' => 'ROLE_VIEWER', default => null,
        };
        static::getContainer()->get(AccountManager::class)->change($current, $operation, 'test-operator', $value);
        $this->browser->request('GET', '/admin');
        $this->assertRedirectPath('/login');
        $audit = $this->em()->getRepository(AuditEntry::class)->findOneBy(['targetType' => 'User', 'targetId' => $id]);
        self::assertSame('cli:test-operator', $audit->getActor());
        self::assertStringNotContainsString('totp', strtolower($audit->getFormattedChanges()));
    }
    #[DataProvider('revocations')]
    public function testAdministrativeChangesRevokeMfaInProgress(string $operation): void
    {
        $user = $this->account();
        $id = $user->getId();
        $this->passwordLogin($user->getEmail());
        $current = $this->em()->find(User::class, $id);
        $value = match ($operation) {
            'password-reset' => static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($current, bin2hex(random_bytes(20))),
            'role' => 'ROLE_VIEWER', default => null,
        };
        static::getContainer()->get(AccountManager::class)->change($current, $operation, 'test-operator', $value);
        $this->browser->request('GET', '/admin');
        $this->assertRedirectPath('/login');
    }
}
