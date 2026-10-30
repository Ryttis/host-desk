<?php

namespace App\Command;

use App\Entity\User;
use App\Service\AccountManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:account', description: 'Create, change role, disable, password-reset or mfa-reset a local account.')]
final class AccountCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private AccountManager $accounts, private UserPasswordHasherInterface $hasher) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('operation', InputArgument::REQUIRED, 'create | role | disable | password-reset | mfa-reset')
            ->addArgument('email', InputArgument::REQUIRED)
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Viewer | Operator | Admin')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Operator label for the audit record (required).');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $operation = $input->getArgument('operation');
        $email = strtolower(trim($input->getArgument('email')));
        $actor = trim((string) $input->getOption('actor'));
        if (!in_array($operation, ['create', 'role', 'disable', 'password-reset', 'mfa-reset'], true)
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180 || $actor === '' || strlen($actor) > 120) {
            $io->error('Provide a valid operation, email and --actor label (1–120 characters).');
            return Command::INVALID;
        }
        $role = User::ROLES[$input->getOption('role') ?? ''] ?? null;
        if (in_array($operation, ['create', 'role'], true) && $role === null) {
            $io->error('Choose --role=Viewer, --role=Operator or --role=Admin.');
            return Command::INVALID;
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (($operation === 'create') === ($user !== null)) {
            $io->error($user ? 'Account already exists.' : 'Account not found.');
            return Command::FAILURE;
        }
        $hash = null;
        if (in_array($operation, ['create', 'password-reset'], true)) {
            if (!$input->isInteractive()) { $io->error('Password operations require interactive hidden input.'); return Command::INVALID; }
            $question = (new Question('Password (at least 14 characters): '))->setHidden(true)->setHiddenFallback(false);
            $password = $io->askQuestion($question);
            $confirmation = $io->askQuestion((new Question('Repeat password: '))->setHidden(true)->setHiddenFallback(false));
            if (!is_string($password) || strlen($password) < 14 || $password !== $confirmation) {
                $io->error('Passwords must match and contain at least 14 characters.');
                return Command::INVALID;
            }
            $hash = $this->hasher->hashPassword($user ?? new User($email), $password);
            sodium_memzero($password);
            sodium_memzero($confirmation);
        }
        if ($operation === 'create') { $this->accounts->create($email, $role, $hash, $actor); }
        else { $this->accounts->change($user, $operation, $actor, $operation === 'role' ? $role : $hash); }
        $io->success('Account change recorded. Existing sessions are invalidated for account changes.');
        return Command::SUCCESS;
    }
}
