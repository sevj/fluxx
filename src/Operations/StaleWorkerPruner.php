<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Prunes {@see \Fluxx\Entity\RuntimeWorkerState} rows that no longer represent
 * a live worker:
 *  - rows already marked "stopped" older than a retention window are hard-deleted;
 *  - rows still flagged "processing" or "idle" whose heartbeat is older than the
 *    stale threshold (ghosts of crashed workers that never recorded a clean stop)
 *    are rewritten to "stopped" and detached from their run/step.
 *
 * Safe to run periodically (cron) and from the runtime UI.
 */
final readonly class StaleWorkerPruner
{
    private const DEFAULT_TRANSPORT = 'fluxx';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{deletedStopped: int, markedStaleAsStopped: int}
     */
    public function prune(
        int $stoppedRetentionSeconds = 0,
        int $staleHeartbeatSeconds = 120,
        ?string $transportName = null,
    ): array {
        $transport = $transportName ?? self::DEFAULT_TRANSPORT;

        if ($staleHeartbeatSeconds < 1) {
            throw new RuntimeException('The stale heartbeat threshold must be greater than zero.');
        }

        $deletedStopped = $this->deleteStoppedRows($transport, $stoppedRetentionSeconds);
        $markedStaleAsStopped = $this->markStaleGhostsAsStopped($transport, $staleHeartbeatSeconds);

        return ['deletedStopped' => $deletedStopped, 'markedStaleAsStopped' => $markedStaleAsStopped];
    }

    private function deleteStoppedRows(string $transport, int $retentionSeconds): int
    {
        if ($retentionSeconds <= 0) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM fluxx_runtime_worker_state
             WHERE transport_name = :transport
               AND status = :status
               AND last_heartbeat_at < :threshold',
            [
                'transport' => $transport,
                'status' => 'stopped',
                'threshold' => (new \DateTimeImmutable(sprintf('-%d seconds', $retentionSeconds)))->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function markStaleGhostsAsStopped(string $transport, int $staleHeartbeatSeconds): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE fluxx_runtime_worker_state
             SET status = :stopped,
                 run_id = NULL,
                 step_code = NULL,
                 workflow_code = NULL,
                 current_message_class = NULL,
                 current_transport_message_id = NULL,
                 started_processing_at = NULL
             WHERE transport_name = :transport
               AND status IN (:processing, :idle)
               AND last_heartbeat_at < :threshold',
            [
                'transport' => $transport,
                'stopped' => 'stopped',
                'processing' => 'processing',
                'idle' => 'idle',
                'threshold' => (new \DateTimeImmutable(sprintf('-%d seconds', $staleHeartbeatSeconds)))->format('Y-m-d H:i:s'),
            ],
        );
    }
}
