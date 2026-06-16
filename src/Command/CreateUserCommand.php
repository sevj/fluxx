<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\User\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:user:create', description: 'Create a Fluxx user.')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email.')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain password.')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Optional display name.')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant the ROLE_ADMIN role.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $roles = ['ROLE_USER'];

        if ((bool) $input->getOption('admin')) {
            $roles[] = 'ROLE_ADMIN';
        }

        $user = $this->userManager->create(
            email: (string) $input->getArgument('email'),
            plainPassword: (string) $input->getArgument('password'),
            roles: $roles,
            displayName: $input->getOption('display-name') ?: null,
        );

        $io->success(sprintf('User "%s" created.', $user->email()));

        return Command::SUCCESS;
    }
}
