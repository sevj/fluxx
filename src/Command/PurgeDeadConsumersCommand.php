<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Operations\DeadConsumerPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:runtime:purge-dead-consumers', description: 'Remove dead Redis consumer entries from the fluxx transport group.')]
final class PurgeDeadConsumersCommand extends Command
{
    public function __construct(
        private readonly DeadConsumerPurger $purger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('min-idle', null, InputOption::VALUE_REQUIRED, 'Consider a consumer dead when its Redis idle time exceeds this many seconds.', 60);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->purger->purge(minIdleSeconds: (int) $input->getOption('min-idle'));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($result['purged'] === []) {
            $io->text('No dead consumer was found.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Consumer', 'Released pending'],
            array_map(
                static fn (array $consumer): array => [$consumer['name'], (string) $consumer['pending']],
                $result['purged'],
            ),
        );

        $io->success(sprintf('Purged %d dead consumer(s); skipped %d live consumer(s).', count($result['purged']), $result['skipped']));

        return Command::SUCCESS;
    }
}
