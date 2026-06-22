<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'fluxx:runtime:reset', description: 'Purge the Fluxx Redis transport and reset Fluxx runtime tables.')]
final class ResetRuntimeCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(env: 'MESSENGER_TRANSPORT_FLUXX_DSN')]
        private readonly string $fluxxTransportDsn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Execute without confirmation.')
            ->addOption('keep-worker-state', null, InputOption::VALUE_NONE, 'Do not truncate fluxx_runtime_worker_state.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keepWorkerState = (bool) $input->getOption('keep-worker-state');

        $transport = $this->parseRedisTransportDsn($this->fluxxTransportDsn);
        if ($transport === null) {
            $io->error(sprintf('Unsupported Fluxx transport DSN "%s".', $this->fluxxTransportDsn));

            return Command::FAILURE;
        }

        if (!(bool) $input->getOption('force')) {
            $io->warning([
                'This will delete all Fluxx queued messages and truncate Fluxx runtime tables.',
                'Workers should be stopped before running this command if you want a fully clean state.',
            ]);

            if (!$io->confirm('Continue?', false)) {
                return Command::INVALID;
            }
        }

        try {
            $redis = $this->createRedisClient($transport);
            $deletedKeys = $redis->del([$transport['stream'], $transport['stream'].'__queue']);
            $groupDestroyed = $this->destroyGroup($redis, $transport['stream'], $transport['group']);

            $tables = [
                'fluxx_workflow_payload',
                'fluxx_workflow_step_run',
                'fluxx_workflow_execution_lock',
                'fluxx_workflow_run',
            ];

            if (!$keepWorkerState) {
                array_unshift($tables, 'fluxx_runtime_worker_state');
            }

            $this->connection->executeStatement(sprintf(
                'TRUNCATE %s RESTART IDENTITY CASCADE',
                implode(', ', $tables),
            ));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Fluxx runtime reset completed. Deleted Redis keys: %d. Group destroyed: %s.',
            $deletedKeys,
            $groupDestroyed ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{host: string, port: int, stream: string, group: string, dbindex: int, auth: string|array<string>|null}|null
     */
    private function parseRedisTransportDsn(string $dsn): ?array
    {
        if (!str_starts_with($dsn, 'redis://') && !str_starts_with($dsn, 'rediss://')) {
            return null;
        }

        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        return [
            'host' => $parts['host'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : 6379,
            'stream' => $segments[0] ?? 'messages',
            'group' => $segments[1] ?? 'symfony',
            'dbindex' => isset($parts['query']) ? $this->extractDbIndex($parts['query']) : 0,
            'auth' => $this->extractAuth($parts),
        ];
    }

    /**
     * @param array{host: string, port: int, stream: string, group: string, dbindex: int, auth: string|array<string>|null} $transport
     */
    private function createRedisClient(array $transport): \Redis
    {
        $redis = new \Redis();
        $connected = $redis->connect($transport['host'], $transport['port']);

        if ($connected !== true) {
            throw new \RuntimeException('Could not connect to the Fluxx Redis transport.');
        }

        if ($transport['auth'] !== null && $redis->auth($transport['auth']) !== true) {
            throw new \RuntimeException('Could not authenticate against the Fluxx Redis transport.');
        }

        if ($transport['dbindex'] > 0 && $redis->select($transport['dbindex']) !== true) {
            throw new \RuntimeException(sprintf('Could not select Redis database %d.', $transport['dbindex']));
        }

        return $redis;
    }

    private function destroyGroup(\Redis $redis, string $stream, string $group): bool
    {
        try {
            return $redis->xGroup('DESTROY', $stream, $group) === true;
        } catch (\RedisException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $parts
     *
     * @return string|array<string>|null
     */
    private function extractAuth(array $parts): string|array|null
    {
        $user = isset($parts['user']) ? urldecode((string) $parts['user']) : null;
        $pass = isset($parts['pass']) ? urldecode((string) $parts['pass']) : null;

        if ($user === null && $pass === null) {
            return null;
        }

        if ($user === null) {
            return (string) $pass;
        }

        return [$user, (string) $pass];
    }

    private function extractDbIndex(string $query): int
    {
        parse_str($query, $params);

        return isset($params['dbindex']) ? (int) $params['dbindex'] : 0;
    }
}
